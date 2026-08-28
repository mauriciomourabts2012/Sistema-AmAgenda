/* ==========================================================
   js/select-lista-universal.js (ANTI-DUPLICAÇÃO)
   - Usa API central do AmAgenda
   - Somente ATIVOS
   - Evita duplicar options (por value)
   - Evita recarregar se já carregou (a menos que force)
   - Estrutura universal para futuras listas
   - PREPARADO PARA:
     1) listar somente Proprietário
     2) listar todos os perfis menos Proprietário
========================================================== */
(() => {
  "use strict";

  const API_URL = "/public/api/api_central.php";

  const LISTAS = {
    perfis_proprietario: {
      // LISTA PERFIS (SOMENTE PROPRIETARIO)
      endpoint: `${API_URL}?path=superadmin/perfil/listar&status=ativo`,
      placeholder: "Perfil",
      autoSelectSingle: true,
      targets: [
        { modalId: "modalCadastrarUsuario", selectId: "u_perfil_super_admin" },
      ],
      filterItems: (lista) => {
        if (!Array.isArray(lista)) return [];

        return lista.filter((p) => {
          const nome = normaliza(p?.nome ?? p?.perfil ?? "");
          return nome === "proprietario";
        });
      },
      mapItem: (p) => ({
        id: p?.id_perfil ?? p?.id ?? "",
        label: (p?.nome ?? p?.perfil ?? "").toString().trim(),
        status: p?.status,
      }),
    },

    perfis_sem_proprietario: {
      // LISTA PERFIS (SEM PROPRIETARIO E SUPER ADMIN)
      endpoint: `${API_URL}?path=superadmin/perfil/listar&status=ativo`,
      placeholder: "Perfil",
      targets: [

        { modalId: "modalNovoUsuario", selectId: "u_perfil" },
        { modalId: "modalEditarUsuario", selectId: "u_e_perfil" },
      ],
      filterItems: (lista) => {
        if (!Array.isArray(lista)) return [];

        return lista.filter((p) => {
          const nome = normaliza(p?.nome ?? p?.perfil ?? "");

          return (
            nome !== "proprietario" &&
            nome !== "super admin" &&
            nome !== "super_admin"
          );
        });
      },
      mapItem: (p) => ({
        id: p?.id_perfil ?? p?.id ?? "",
        label: (p?.nome ?? p?.perfil ?? "").toString().trim(),
        status: p?.status,
      }),
    },

    planos_ativos: {
      // LISTA PLANOS
      endpoint: `${API_URL}?path=superadmin/plano/listar&status=ativo`,
      placeholder: "Plano",
      targets: [
        { modalId: "modalCadastrarEmpresa", selectId: "emp_plano" },
        { modalId: "modalEditarEmpresa", selectId: "emp_edit_plano" },
      ],
      mapItem: (p) => ({
        id: p?.id_plano ?? p?.id ?? "",
        label: (p?.nome ?? p?.plano ?? p?.titulo ?? "").toString().trim(),
        status: p?.status,
      }),
    },

    empresas_ativas: {
      // LISTA EMPRESAS
      endpoint: `${API_URL}?path=superadmin/empresa/listar&status=ativo&ordem=nome_asc&limit=100`,
      placeholder: "Empresa",
      targets: [
        { modalId: "modalCadastrarUsuario", selectId: "u_empresa_super_admin" },
        { modalId: "modalEditarUsuario", selectId: "edit_u_empresa_super_admin" },
        { selectId: "empresa_auditoria", placeholder: "Todas" },
      ],
      mapItem: (e) => ({
        id: e?.id_empresa ?? e?.empresa_id ?? e?.id ?? "",
        label: (e?.nome ?? e?.nome_fantasia ?? e?.razao_social ?? e?.empresa ?? "")
          .toString()
          .trim(),
        status: e?.status,
      }),
    },
  };

  // =========================
  // HELPERS
  // =========================
  const ativoValues = ["ativo", "1", "true", "sim", "atv"];

  function normaliza(txt) {
    return String(txt ?? "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .trim();
  }

  function isAtivo(status) {
    return ativoValues.includes(normaliza(status));
  }

  function listaFromResponse(json) {
    if (Array.isArray(json)) return json;
    if (Array.isArray(json?.data)) return json.data;
    if (Array.isArray(json?.data?.items)) return json.data.items;
    if (Array.isArray(json?.rows)) return json.rows;
    if (Array.isArray(json?.dados)) return json.dados;
    if (Array.isArray(json?.lista)) return json.lista;
    return [];
  }

  function resetSelect(select, placeholder) {
    select.innerHTML = "";

    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = placeholder || "Selecione";
    select.appendChild(opt);
  }

  function appendOptionDedup(select, value, text) {
    const v = String(value ?? "").trim();
    const t = String(text ?? "").trim();

    if (!v || !t) return false;

    if (select.querySelector(`option[value="${CSS.escape(v)}"]`)) {
      return false;
    }

    const opt = document.createElement("option");
    opt.value = v;
    opt.textContent = t;
    opt.dataset.by = "select-lista-universal";
    select.appendChild(opt);
    return true;
  }

  async function fetchLista(cfg) {
    const resp = await fetch(cfg.endpoint, {
      method: "GET",
      headers: { Accept: "application/json" },
      cache: "no-store",
      credentials: "same-origin",
    });

    const json = await resp.json().catch(() => null);

    if (!resp.ok || !json) {
      console.warn("[select-lista-universal] resposta inválida:", {
        status: resp.status,
        endpoint: cfg.endpoint,
      });
      return [];
    }

    if (json?.ok === false) {
      console.warn("[select-lista-universal] API retornou erro:", json);
      return [];
    }

    let lista = listaFromResponse(json);

    if (typeof cfg.filterItems === "function") {
      lista = cfg.filterItems(lista);
    }

    return lista
      .map(cfg.mapItem)
      .filter((x) => {
        if (!x || !x.id) return false;

        const label = String(x.label || "").trim();
        if (!label) return false;

        if (!isAtivo(x.status)) return false;

        return true;
      });
  }

  /**
   * carregar(cfgKey, selectId, opts)
   * opts.force = true => recarrega mesmo se já carregou
   */
  async function carregar(cfgKey, selectId, opts = {}) {
    const cfg = LISTAS[cfgKey];
    if (!cfg) return;

    const select = document.getElementById(selectId);
    if (!select) return;

    if (select.dataset.loading === "1") return;
    select.dataset.loading = "1";

    try {
      if (!opts.force && select.dataset.loaded === "1") {
        select.classList.remove("hidden");
        return;
      }

      const keepValue = select.value;
      const target = (cfg.targets || []).find((item) => item.selectId === selectId);
      resetSelect(select, target?.placeholder || cfg.placeholder);

      const items = await fetchLista(cfg);

      items.forEach((it) => appendOptionDedup(select, it.id, it.label));

      if (keepValue && select.querySelector(`option[value="${CSS.escape(String(keepValue))}"]`)) {
        select.value = keepValue;
      } else if (cfg.autoSelectSingle && items.length === 1) {
        select.value = String(items[0].id);
      }

      select.classList.remove("hidden");
      select.dataset.loaded = "1";
    } catch (e) {
      console.error(`[select-lista-universal] (${cfgKey}/${selectId})`, e);
      select.classList.remove("hidden");
      select.innerHTML = `<option value="">Sem opção disponível</option>`;
    } finally {
      select.dataset.loading = "0";
    }
  }

  async function carregarLista(cfgKey, opts = {}) {
    const cfg = LISTAS[cfgKey];
    if (!cfg) return;

    const targets = Array.isArray(cfg.targets) ? cfg.targets : [];
    for (const t of targets) {
      await carregar(cfgKey, t.selectId, opts);
    }
  }

  // =========================
  // 1) Carrega ao abrir a página
  // =========================
  document.addEventListener("DOMContentLoaded", () => {
    Object.entries(LISTAS).forEach(([key, cfg]) => {
      (cfg.targets || []).forEach((t) => {
        carregar(key, t.selectId, { force: true });
      });
    });
  });

  // =========================
  // 2) Carrega ao abrir modal
  // =========================
  document.addEventListener(
    "click",
    (e) => {
      const btn = e.target.closest("button[data-abrir-modal]");
      if (!btn) return;

      const modalId = btn.getAttribute("data-abrir-modal");
      if (!modalId) return;

      Object.entries(LISTAS).forEach(([key, cfg]) => {
        const t = (cfg.targets || []).find((x) => x.modalId === modalId);
        if (t) {
          carregar(key, t.selectId, { force: false });
        }
      });
    },
    true
  );

  window.SelectListaUniversal = {
    carregar,
    carregarLista,
    listas: LISTAS,
  };
})();
