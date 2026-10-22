/* ==========================================================
   processador-imagem.js — Componente compartilhado | Otimização de imagens
   Expõe: window.AmAgendaImageProcessor

   ✅ JPEG, PNG e WebP (tipo real identificado pelo cabeçalho do arquivo)
   ✅ Limite de entrada separado do limite do arquivo final enviado
   ✅ Dimensões lidas do cabeçalho ANTES de decodificar (proteção de memória)
   ✅ Redimensionamento proporcional (lado maior <= maxSide), sem ampliar
   ✅ Orientação EXIF aplicada na decodificação
   ✅ Transparência preservada (PNG/WebP -> WebP; PNG se não houver encoder WebP)
   ✅ Compressão via canvas.toBlob() (sem base64)
   ✅ Mantém o arquivo original quando já está adequado
   ✅ Uma imagem por vez; recursos temporários liberados no finally
   ✅ Sem dependências externas

   Limites padrão (justificativa):
   - maxInputBytes 30 MB: limite inicial de homologação para originais de câmera.
   - maxPixels 50 MP: uma imagem decodificada ocupa ~4 bytes/pixel; 50 MP ≈ 200 MB
     de RAM, suficiente para câmeras de 48–50 MP. Em aparelhos que informam
     navigator.deviceMemory <= 2 GB, o limite cai para 24 MP (≈ 96 MB).
   - maxInputSide 16384 px: maior dimensão suportada de forma consistente pelos
     decodificadores/canvas dos navegadores atuais.
   - O canvas de saída nunca passa de maxSide × maxSide (padrão 1024), bem abaixo
     do limite de área de canvas do iOS (~16,7 MP).

   HEIC/HEIF (fase futura): o ponto de extensão é decodeImage() + readImageInfo().
   Um decodificador futuro deve devolver { source, width, height, release } e o
   restante do fluxo permanece igual. Nada é liberado nesta fase.
========================================================== */
(() => {
  "use strict";

  if (window.AmAgendaImageProcessor) {
    return;
  }

  const MB = 1024 * 1024;
  const DEFAULTS = Object.freeze({
    maxSide: 1024,
    maxInputBytes: 30 * MB,
    keepOriginalMaxBytes: 1 * MB,
    maxPixels: 50000000,
    lowMemoryMaxPixels: 24000000,
    maxInputSide: 16384
  });
  const QUALITY_STEPS = [0.85, 0.78, 0.7, 0.6];
  const EXT_BY_TYPE = {
    "image/jpeg": "jpg",
    "image/png": "png",
    "image/webp": "webp"
  };

  const MSG_INVALID = "Não foi possível ler a imagem. O arquivo pode estar corrompido ou em um formato não suportado.";
  const MSG_TYPE = "Formato inválido. Envie uma imagem JPG, PNG ou WEBP.";
  const MSG_MEMORY = "Não foi possível processar a imagem neste dispositivo. Tente uma imagem menor.";

  let busy = false;

  class ImageProcessError extends Error {
    constructor(code, message) {
      super(message);
      this.name = "ImageProcessError";
      this.code = code;
    }
  }

  function formatMb(bytes) {
    return `${Math.round(bytes / MB)} MB`;
  }

  function isLowMemoryDevice() {
    const mem = Number(typeof navigator !== "undefined" ? navigator.deviceMemory : 0);
    return mem > 0 && mem <= 2;
  }

  function isSupported() {
    return (
      typeof document !== "undefined" &&
      typeof HTMLCanvasElement !== "undefined" &&
      typeof HTMLCanvasElement.prototype.toBlob === "function" &&
      typeof URL !== "undefined" &&
      typeof URL.createObjectURL === "function" &&
      typeof Blob !== "undefined" &&
      typeof Blob.prototype.slice === "function" &&
      (typeof Blob.prototype.arrayBuffer === "function" || typeof FileReader !== "undefined")
    );
  }

  /* ==========================================================
     LEITURA DO CABEÇALHO (sem decodificar a imagem)
  ========================================================== */
  function readBytes(blob, start, length) {
    const part = blob.slice(start, start + length);

    if (typeof part.arrayBuffer === "function") {
      return part.arrayBuffer().then((buf) => new DataView(buf));
    }

    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(new DataView(reader.result));
      reader.onerror = () => reject(reader.error);
      reader.readAsArrayBuffer(part);
    });
  }

  function ascii(view, offset, length) {
    let out = "";
    for (let i = 0; i < length && offset + i < view.byteLength; i++) {
      out += String.fromCharCode(view.getUint8(offset + i));
    }
    return out;
  }

  async function readJpegInfo(file) {
    let pos = 2;

    for (let i = 0; i < 512 && pos < file.size; i++) {
      const v = await readBytes(file, pos, 12);
      if (v.byteLength < 2 || v.getUint8(0) !== 0xFF) return null;

      const marker = v.getUint8(1);

      if (marker === 0xFF) { pos += 1; continue; } // byte de preenchimento
      if (marker === 0x01 || (marker >= 0xD0 && marker <= 0xD8)) { pos += 2; continue; }
      if (marker === 0xD9 || marker === 0xDA) return null; // fim/scan antes do SOF
      if (v.byteLength < 4) return null;

      const length = v.getUint16(2);
      if (length < 2) return null;

      const isSof = marker >= 0xC0 && marker <= 0xCF &&
        marker !== 0xC4 && marker !== 0xC8 && marker !== 0xCC;

      if (isSof) {
        if (v.byteLength < 9) return null;
        return { type: "image/jpeg", height: v.getUint16(5), width: v.getUint16(7) };
      }

      pos += 2 + length;
    }

    return null;
  }

  async function readImageInfo(file) {
    const head = await readBytes(file, 0, 32);
    if (head.byteLength < 12) return null;

    const b = (i) => head.getUint8(i);

    // PNG: assinatura + IHDR
    if (b(0) === 0x89 && ascii(head, 1, 3) === "PNG" && b(4) === 0x0D && b(5) === 0x0A && b(6) === 0x1A && b(7) === 0x0A) {
      if (head.byteLength < 24 || ascii(head, 12, 4) !== "IHDR") return null;
      return { type: "image/png", width: head.getUint32(16), height: head.getUint32(20) };
    }

    // WebP: RIFF....WEBP + VP8 / VP8L / VP8X
    if (ascii(head, 0, 4) === "RIFF" && ascii(head, 8, 4) === "WEBP") {
      const chunk = ascii(head, 12, 4);

      if (chunk === "VP8 " && head.byteLength >= 30) {
        if (b(23) !== 0x9D || b(24) !== 0x01 || b(25) !== 0x2A) return null;
        return {
          type: "image/webp",
          width: head.getUint16(26, true) & 0x3FFF,
          height: head.getUint16(28, true) & 0x3FFF
        };
      }

      if (chunk === "VP8L" && head.byteLength >= 25) {
        if (b(20) !== 0x2F) return null;
        return {
          type: "image/webp",
          width: 1 + (((b(22) & 0x3F) << 8) | b(21)),
          height: 1 + (((b(24) & 0x0F) << 10) | (b(23) << 2) | ((b(22) & 0xC0) >> 6))
        };
      }

      if (chunk === "VP8X" && head.byteLength >= 30) {
        return {
          type: "image/webp",
          width: 1 + (b(24) | (b(25) << 8) | (b(26) << 16)),
          height: 1 + (b(27) | (b(28) << 8) | (b(29) << 16))
        };
      }

      return null;
    }

    // JPEG: SOI + percorre segmentos até o SOF
    if (b(0) === 0xFF && b(1) === 0xD8) {
      return readJpegInfo(file);
    }

    return null;
  }

  /* ==========================================================
     DECODIFICAÇÃO
  ========================================================== */
  function decodeViaImg(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      const release = () => {
        img.onload = null;
        img.onerror = null;
        img.removeAttribute("src");
        URL.revokeObjectURL(url);
      };

      img.decoding = "async";
      img.onload = () => resolve({
        source: img,
        width: img.naturalWidth,
        height: img.naturalHeight,
        release
      });
      img.onerror = () => {
        release();
        reject(new ImageProcessError("DECODE_FAILED", MSG_INVALID));
      };
      img.src = url;
    });
  }

  async function decodeImage(file) {
    if (typeof createImageBitmap === "function") {
      try {
        // "from-image" aplica a orientação EXIF (fotos de celular).
        const bmp = await createImageBitmap(file, { imageOrientation: "from-image" });
        return {
          source: bmp,
          width: bmp.width,
          height: bmp.height,
          release: () => {
            try { bmp.close(); } catch (_) { /* noop */ }
          }
        };
      } catch (_) {
        // Fallback para <img> (navegadores sem suporte às opções ou falha pontual).
      }
    }

    return decodeViaImg(file);
  }

  /* ==========================================================
     CODIFICAÇÃO
  ========================================================== */
  function canvasToBlob(canvas, type, quality) {
    return new Promise((resolve) => {
      try {
        canvas.toBlob((blob) => resolve(blob || null), type, quality);
      } catch (_) {
        resolve(null);
      }
    });
  }

  async function encode(canvas, preferredType, maxBytes) {
    const steps = preferredType === "image/png" ? [undefined] : QUALITY_STEPS;
    let last = null;

    for (const quality of steps) {
      const blob = await canvasToBlob(canvas, preferredType, quality);
      if (!blob) {
        throw new ImageProcessError("ENCODE_FAILED", MSG_MEMORY);
      }

      // Encoder sem suporte ao tipo (ex.: WebP em Safari antigo devolve PNG).
      if (blob.type !== preferredType) {
        if (preferredType === "image/webp") {
          return encode(canvas, "image/png", maxBytes);
        }
        throw new ImageProcessError("ENCODE_UNSUPPORTED", MSG_MEMORY);
      }

      last = blob;
      if (blob.size <= maxBytes) break;
    }

    return last;
  }

  function buildFileName(originalName, type) {
    const ext = EXT_BY_TYPE[type] || "img";
    const base = String(originalName || "")
      .replace(/\.[^.]*$/, "")
      .replace(/[^\w-]+/g, "_")
      .slice(0, 60) || "foto";
    return `${base}.${ext}`;
  }

  function toFile(blob, originalName) {
    const fileName = buildFileName(originalName, blob.type);
    try {
      return new File([blob], fileName, { type: blob.type, lastModified: Date.now() });
    } catch (_) {
      try { blob.name = fileName; } catch (__) { /* noop */ }
      return blob;
    }
  }

  function resolveOptions(options) {
    const opts = options || {};
    const num = (value, fallback) => (Number(value) > 0 ? Number(value) : fallback);
    const maxBytes = num(opts.maxBytes, 0);

    if (!maxBytes) {
      throw new ImageProcessError("CONFIG_INVALID", "Configuração de envio de imagem inválida.");
    }

    return {
      maxBytes,
      allowedTypes: Array.isArray(opts.allowedTypes) ? opts.allowedTypes : Object.keys(EXT_BY_TYPE),
      maxSide: num(opts.maxSide, DEFAULTS.maxSide),
      maxInputBytes: Math.max(num(opts.maxInputBytes, DEFAULTS.maxInputBytes), maxBytes),
      keepOriginalMaxBytes: Math.min(num(opts.keepOriginalMaxBytes, DEFAULTS.keepOriginalMaxBytes), maxBytes),
      maxPixels: num(opts.maxPixels, isLowMemoryDevice() ? DEFAULTS.lowMemoryMaxPixels : DEFAULTS.maxPixels),
      maxInputSide: num(opts.maxInputSide, DEFAULTS.maxInputSide)
    };
  }

  /* ==========================================================
     PROCESSAMENTO
     options:
       maxBytes (obrigatório)   limite do arquivo FINAL enviado
       allowedTypes             tipos aceitos no envio
       maxInputBytes            limite do arquivo ORIGINAL (padrão 30 MB)
       maxSide                  lado maior da saída (padrão 1024 px)
       keepOriginalMaxBytes     até este tamanho e <= maxSide, mantém o original
       maxPixels / maxInputSide limites de segurança de memória
     retorno: { file, optimized, reason, width, height,
                originalWidth, originalHeight, originalSize }
  ========================================================== */
  async function process(file, options) {
    const opts = resolveOptions(options);

    if (!file || typeof file.size !== "number" || typeof file.slice !== "function") {
      throw new ImageProcessError("INVALID_FILE", MSG_INVALID);
    }

    if (file.size <= 0) {
      throw new ImageProcessError("EMPTY_FILE", "O arquivo selecionado está vazio.");
    }

    if (file.size > opts.maxInputBytes) {
      throw new ImageProcessError("INPUT_TOO_LARGE", `A imagem deve ter no máximo ${formatMb(opts.maxInputBytes)}.`);
    }

    if (!isSupported()) {
      // Navegador sem os recursos necessários: só envia o original se ele já
      // respeitar o limite final; nunca transmite arquivo acima do backend.
      if (file.size <= opts.maxBytes && opts.allowedTypes.includes(file.type)) {
        return {
          file,
          optimized: false,
          reason: "unsupported-original",
          width: 0,
          height: 0,
          originalWidth: 0,
          originalHeight: 0,
          originalSize: file.size
        };
      }

      throw new ImageProcessError(
        "UNSUPPORTED_BROWSER",
        `Este navegador não consegue otimizar a imagem antes do envio. Envie uma imagem de até ${formatMb(opts.maxBytes)} ou atualize o navegador.`
      );
    }

    if (busy) {
      throw new ImageProcessError("BUSY", "Aguarde o processamento da imagem atual.");
    }

    busy = true;
    let decoded = null;
    let canvas = null;

    try {
      let info = null;
      try {
        info = await readImageInfo(file);
      } catch (_) {
        info = null;
      }

      if (!info) {
        throw new ImageProcessError("HEADER_INVALID", MSG_INVALID);
      }

      if (!opts.allowedTypes.includes(info.type)) {
        throw new ImageProcessError("TYPE_NOT_ALLOWED", MSG_TYPE);
      }

      if (!(info.width > 0 && info.height > 0)) {
        throw new ImageProcessError("INVALID_DIMENSIONS", MSG_INVALID);
      }

      if (
        info.width > opts.maxInputSide ||
        info.height > opts.maxInputSide ||
        info.width * info.height > opts.maxPixels
      ) {
        const mp = Math.round(opts.maxPixels / 1000000);
        throw new ImageProcessError(
          "DIMENSIONS_EXCEEDED",
          `A resolução da imagem (${info.width} × ${info.height} px) excede o limite seguro de processamento (${mp} MP). Reduza a resolução da foto e tente novamente.`
        );
      }

      try {
        decoded = await decodeImage(file);
      } catch (err) {
        if (err instanceof ImageProcessError) throw err;
        throw new ImageProcessError("DECODE_FAILED", MSG_INVALID);
      }

      const width = Number(decoded.width) || 0;
      const height = Number(decoded.height) || 0;
      if (width <= 0 || height <= 0 || width * height > opts.maxPixels) {
        throw new ImageProcessError("INVALID_DIMENSIONS", MSG_INVALID);
      }

      const scale = Math.min(1, opts.maxSide / Math.max(width, height));
      const needsResize = scale < 1;
      const base = {
        originalWidth: width,
        originalHeight: height,
        originalSize: file.size
      };

      if (!needsResize && file.size <= opts.keepOriginalMaxBytes) {
        return { ...base, file, optimized: false, reason: "original", width, height };
      }

      const targetWidth = Math.max(1, Math.round(width * scale));
      const targetHeight = Math.max(1, Math.round(height * scale));

      canvas = document.createElement("canvas");
      canvas.width = targetWidth;
      canvas.height = targetHeight;

      const ctx = canvas.getContext("2d");
      if (!ctx) {
        throw new ImageProcessError("CANVAS_UNAVAILABLE", MSG_MEMORY);
      }

      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = "high";
      ctx.drawImage(decoded.source, 0, 0, targetWidth, targetHeight);

      // Libera a imagem decodificada (maior consumo de memória) antes de codificar.
      decoded.release();
      decoded = null;

      const preferredType = info.type === "image/jpeg" ? "image/jpeg" : "image/webp";
      const blob = await encode(canvas, preferredType, opts.maxBytes);

      // Sem redimensionamento e sem ganho: mantém o original somente se ele
      // respeitar o limite final (nunca envia original acima do backend).
      if (!needsResize && blob.size >= file.size && file.size <= opts.maxBytes) {
        return { ...base, file, optimized: false, reason: "original", width, height };
      }

      if (!opts.allowedTypes.includes(blob.type)) {
        throw new ImageProcessError("OUTPUT_TYPE_INVALID", MSG_MEMORY);
      }

      if (blob.size > opts.maxBytes) {
        throw new ImageProcessError(
          "OUTPUT_TOO_LARGE",
          `Não foi possível reduzir a imagem para até ${formatMb(opts.maxBytes)}.`
        );
      }

      return {
        ...base,
        file: toFile(blob, file.name),
        optimized: true,
        reason: "optimized",
        width: targetWidth,
        height: targetHeight
      };
    } catch (err) {
      if (err instanceof ImageProcessError) throw err;
      // RangeError, falhas de memória do canvas etc.
      throw new ImageProcessError("PROCESS_FAILED", MSG_MEMORY);
    } finally {
      if (decoded) decoded.release();
      if (canvas) {
        canvas.width = 0;
        canvas.height = 0;
      }
      busy = false;
    }
  }

  window.AmAgendaImageProcessor = Object.freeze({
    process,
    isSupported,
    readImageInfo,
    ImageProcessError,
    DEFAULTS,
    MAX_SIDE: DEFAULTS.maxSide
  });
})();
