// Lista profissionais e serviços no modal novo agendamento
(() => {
  "use strict";

  const selectProfissional = document.getElementById("ag_profissional");
  const selectServico = document.getElementById("ag_servico");
  const selectDuracao = document.getElementById("ag_duracao");
  const modal = document.getElementById("modalNovoAgendamento");
  const form = document.getElementById("formNovoAgendamento");
  const modalNovoServico = document.getElementById("modalNovoServicoAgendamento");
  const inputServicoProfissionalId = document.getElementById("serv_ag_id_profissional");
  const inputServicoProfissionalNome = document.getElementById("serv_ag_profissional_nome");

  if (!selectProfissional || !selectServico) return;

  const ACAO_NOVO_SERVICO = "__novo_servico__";

  let profissionaisCarregados = false;
  let abortServicos = null;

  function normalizar(txt) {
    return String(txt ?? "").trim();
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function getLista(json) {
    if (Array.isArray(json)) return json;
    if (Array.isArray(json?.data)) return json.data;
    if (Array.isArray(json?.data?.servicos)) return json.data.servicos;
    if (Array.isArray(json?.data?.profissionais)) return json.data.profissionais;
    if (Array.isArray(json?.data?.items)) return json.data.items;
    if (Array.isArray(json?.rows)) return json.rows;
    if (Array.isArray(json?.dados)) return json.dados;
    if (Array.isArray(json?.lista)) return json.lista;
    return [];
  }

  function formatarValorBR(valor) {
    if (valor === null || valor === undefined || valor === "") return "";

    const numero = Number(String(valor).replace(",", "."));

    if (!Number.isFinite(numero)) {
      return String(valor);
    }

    return numero.toLocaleString("pt-BR", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function mapProfissional(p) {
    return {
      id_profissional: String(
        p?.id_profissional ??
        p?.profissional_id ??
        p?.id ??
        ""
      ).trim(),

      id_usuario: String(
        p?.id_usuario ??
        p?.usuario_id ??
        ""
      ).trim(),

      nome: normalizar(
        p?.nome ??
        p?.nome_completo ??
        p?.usuario ??
        p?.profissional ??
        ""
      ),

      telefone: normalizar(p?.telefone ?? ""),
      email: normalizar(p?.email ?? ""),
      especialidade: normalizar(p?.especialidade ?? ""),
      descricao: normalizar(p?.descricao ?? ""),
      status: normalizar(p?.status ?? "ativo")
    };
  }

  function mapServico(s) {
    return {
      id_servico: String(
        s?.id_servico ??
        s?.servico_id ??
        s?.id ??
        ""
      ).trim(),

      nome: normalizar(
        s?.nome ??
        s?.servico ??
        s?.descricao_servico ??
        ""
      ),

      descricao: normalizar(s?.descricao ?? ""),

      duracao_min: normalizar(
        s?.duracao_min ??
        s?.duracao_minutos ??
        s?.duracao ??
        ""
      ),

      valor: normalizar(s?.valor ?? s?.preco ?? ""),
      status: normalizar(s?.status ?? "ativo")
    };
  }

  function getIdServico(servico) {
    return String(
      servico?.id_servico ??
      servico?.servico_id ??
      servico?.id ??
      ""
    ).trim();
  }

  function preencherSelect(select, html) {
    select.innerHTML = html;
  }

  function normalizarComparacao(txt) {
    return String(txt ?? "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .trim()
      .toLowerCase()
      .replace(/\s+/g, " ");
  }

  function getAuthUsuario() {
    return window.__AUTH__ || window.AUTH_USER || window.usuarioLogado || null;
  }

  function isUsuarioProfissionalLogado() {
    const auth = getAuthUsuario();
    const perfil = normalizarComparacao(
      auth?.perfil_nome ??
      auth?.perfil ??
      auth?.profile ??
      ""
    );

    return perfil === "profissional" || perfil === "profissionais";
  }

  function getIdUsuarioLogado() {
    const auth = getAuthUsuario();

    return String(
      auth?.id_usuario ??
      auth?.usuario_id ??
      auth?.id ??
      ""
    ).trim();
  }

  function selecionarProfissionalPorId(idProfissional) {
    idProfissional = normalizar(idProfissional);

    if (!idProfissional) return false;

    const option = Array.from(selectProfissional.options).find((opt) => {
      return String(opt.value) === String(idProfissional);
    });

    if (!option || !option.value) return false;
    if (selectProfissional.value === option.value) return true;

    selectProfissional.value = option.value;
    selectProfissional.dispatchEvent(new Event("change", { bubbles: true }));
    return true;
  }

  function selecionarProfissionalLogadoSeAplicavel(meta = null) {
    const profissionalLogado = meta?.profissional_logado || null;

    if (profissionalLogado?.eh_profissional && profissionalLogado?.id_profissional) {
      return selecionarProfissionalPorId(profissionalLogado.id_profissional);
    }

    if (!isUsuarioProfissionalLogado()) return false;

    const idUsuarioLogado = getIdUsuarioLogado();
    if (!idUsuarioLogado) return false;

    const option = Array.from(selectProfissional.options).find((opt) => {
      return String(opt.getAttribute("data-id-usuario") || "") === String(idUsuarioLogado);
    });

    return selecionarProfissionalPorId(option.value);
  }

  function optionPadrao(texto) {
    return `<option value="">${escapeHtml(texto)}</option>`;
  }

  function optionNovoServico() {
    return `<option value="${ACAO_NOVO_SERVICO}">+ Cadastrar novo serviço</option>`;
  }

  function getProfissionalSelecionado() {
    const option = selectProfissional.options[selectProfissional.selectedIndex];

    return {
      id: normalizar(selectProfissional.value),
      nome: normalizar(
        option?.getAttribute("data-nome") ||
        option?.textContent ||
        ""
      )
    };
  }

  function abrirModalNovoServico() {
    const profissional = getProfissionalSelecionado();

    if (!profissional.id) {
      selectServico.value = "";
      alert("Selecione um profissional antes de cadastrar um serviço.");
      selectProfissional.focus();
      return;
    }

    if (inputServicoProfissionalId) {
      inputServicoProfissionalId.value = profissional.id;
    }

    if (inputServicoProfissionalNome) {
      inputServicoProfissionalNome.value = profissional.nome;
    }

    if (typeof window.abrirModal === "function") {
      window.abrirModal("modalNovoServicoAgendamento");
    } else if (modalNovoServico) {
      modalNovoServico.classList.add("ativo");
      modalNovoServico.setAttribute("aria-hidden", "false");
    }

    selectServico.value = "";
  }

  function setProfissionalCarregando() {
    preencherSelect(selectProfissional, optionPadrao("Carregando profissionais..."));
  }

  function setProfissionalErro(texto) {
    preencherSelect(selectProfissional, optionPadrao(texto));
  }

  function setServicoCarregando() {
    preencherSelect(selectServico, optionPadrao("Carregando serviços..."));
  }

  function setServicoErro(texto) {
    preencherSelect(selectServico, optionPadrao(texto));
  }

  function limparServicos(texto = "Selecione o Profissional para escolher serviço") {
    const temProfissional = normalizar(selectProfissional.value) !== "";

    preencherSelect(
      selectServico,
      optionPadrao(texto) + (temProfissional ? optionNovoServico() : "")
    );

    if (selectDuracao) {
      selectDuracao.value = "";
    }
  }

  function duracaoLabel(minutos) {
    const min = Number(String(minutos || "").replace(",", "."));

    if (!Number.isFinite(min) || min <= 0) {
      return "Duração";
    }

    if (min < 60) {
      return `${min} min`;
    }

    const horas = Math.floor(min / 60);
    const resto = min % 60;

    return resto > 0 ? `${horas}h${String(resto).padStart(2, "0")}` : `${horas}h`;
  }

  function preencherDuracaoServico(duracao) {
    if (!selectDuracao) return;

    duracao = normalizar(duracao);

    if (!duracao) {
      selectDuracao.value = "";
      return;
    }

    let option = Array.from(selectDuracao.options).find((opt) => {
      return String(opt.value) === String(duracao);
    });

    if (!option) {
      option = new Option(duracaoLabel(duracao), duracao);
      option.dataset.geradaServico = "1";
      selectDuracao.appendChild(option);
    }

    selectDuracao.value = duracao;
    selectDuracao.dispatchEvent(new Event("change", { bubbles: true }));
  }

  async function carregarProfissionais() {
    if (profissionaisCarregados) return;

    setProfissionalCarregando();
    limparServicos();

    try {
      const params = new URLSearchParams();
      params.set("path", "agenda/profissional-modal-novo-agendamento/listar");
      params.set("status", "ativo");
      params.set("pagina", "1");
      params.set("limite", "200");

      const resp = await fetch(`/public/api/api_central.php?${params.toString()}`, {
        method: "GET",
        headers: {
          Accept: "application/json"
        },
        cache: "no-store",
        credentials: "same-origin"
      });

      const json = await resp.json().catch(() => null);

      if (!resp.ok || !json || json?.ok === false) {
        console.error("[profissionais-modal-agendamento] resposta inválida:", json);
        setProfissionalErro("Erro ao carregar profissionais");
        return;
      }

      const lista = getLista(json)
        .map(mapProfissional)
        .filter((p) => p.id_profissional && p.nome);

      if (!lista.length) {
        setProfissionalErro("Nenhum profissional encontrado");
        return;
      }

      const options = lista.map((p) => {
        const complemento = p.especialidade ? ` - ${p.especialidade}` : "";

        return `
          <option 
            value="${escapeHtml(p.id_profissional)}"
            data-id-usuario="${escapeHtml(p.id_usuario)}"
            data-nome="${escapeHtml(p.nome)}"
            data-telefone="${escapeHtml(p.telefone)}"
            data-email="${escapeHtml(p.email)}"
            data-especialidade="${escapeHtml(p.especialidade)}"
          >
            ${escapeHtml(p.nome + complemento)}
          </option>
        `;
      }).join("");

      preencherSelect(selectProfissional, `
        <option value="">Selecione o profissional</option>
        ${options}
      `);

      profissionaisCarregados = true;
      selecionarProfissionalLogadoSeAplicavel(json);
    } catch (err) {
      console.error("[profissionais-modal-agendamento]", err);
      setProfissionalErro("Não foi possível carregar profissionais");
    }
  }

  async function carregarServicosDoProfissional(idProfissional, idServicoSelecionar = "") {
    idProfissional = normalizar(idProfissional);
    idServicoSelecionar = normalizar(idServicoSelecionar);

    if (!idProfissional) {
      limparServicos("Selecione o Profissional para escolher serviço");
      return;
    }

    if (abortServicos) {
      abortServicos.abort();
    }

    abortServicos = new AbortController();

    setServicoCarregando();

    try {
      const params = new URLSearchParams();
      params.set("path", "agenda/servico-profissional/listar");
      params.set("id_profissional", idProfissional);
      params.set("status", "ativo");

      const resp = await fetch(`/public/api/api_central.php?${params.toString()}`, {
        method: "GET",
        headers: {
          Accept: "application/json"
        },
        cache: "no-store",
        credentials: "same-origin",
        signal: abortServicos.signal
      });

      const json = await resp.json().catch(() => null);

      if (!resp.ok || !json || json?.ok === false) {
        console.error("[servicos-profissional-modal-agendamento] resposta inválida:", json);
        setServicoErro("Erro ao carregar serviços");
        return;
      }

      const lista = getLista(json)
        .map(mapServico)
        .filter((s) => s.id_servico && s.nome);

      if (!lista.length) {
        preencherSelect(selectServico, `
          <option value="">Nenhum serviço para este profissional</option>
          ${optionNovoServico()}
        `);
        return;
      }

      const options = lista.map((s) => {
        const textoOption = s.valor !== ""
          ? `${s.nome} - R$ ${formatarValorBR(s.valor)}`
          : s.nome;

        return `
          <option
            value="${escapeHtml(s.id_servico)}"
            data-nome="${escapeHtml(s.nome)}"
            data-duracao="${escapeHtml(s.duracao_min)}"
            data-valor="${escapeHtml(s.valor)}"
          >
            ${escapeHtml(textoOption)}
          </option>
        `;
      }).join("");

      preencherSelect(selectServico, `
        <option value="">Selecione o serviço</option>
        ${options}
        ${optionNovoServico()}
      `);

      if (idServicoSelecionar) {
        selectServico.value = idServicoSelecionar;
        selectServico.dispatchEvent(new Event("change", { bubbles: true }));
      }
    } catch (err) {
      if (err?.name === "AbortError") return;

      console.error("[servicos-profissional-modal-agendamento]", err);
      setServicoErro("Não foi possível carregar serviços");
    }
  }

  selectProfissional.addEventListener("change", () => {
    const idProfissional = selectProfissional.value;

    limparServicos();
    preencherDuracaoServico("");

    if (!idProfissional) {
      return;
    }

    carregarServicosDoProfissional(idProfissional);
  });

  selectServico.addEventListener("change", () => {
    if (selectServico.value === ACAO_NOVO_SERVICO) {
      abrirModalNovoServico();
      return;
    }

    if (!selectDuracao) return;

    const option = selectServico.options[selectServico.selectedIndex];
    const duracao = normalizar(option?.getAttribute("data-duracao"));

    preencherDuracaoServico(duracao);
  });

  document.addEventListener("agenda:servico-agendamento:cadastrado", (e) => {
    const servico = e?.detail?.servico || null;
    const idServico = getIdServico(servico);
    const idProfissionalServico = normalizar(
      servico?.id_profissional ??
      e?.detail?.resposta?.data?.id_profissional ??
      ""
    );
    const idProfissionalAtual = normalizar(selectProfissional.value);

    if (!idProfissionalAtual) return;
    if (idProfissionalServico && idProfissionalServico !== idProfissionalAtual) return;

    carregarServicosDoProfissional(idProfissionalAtual, idServico);
  });

  if (form) {
    form.addEventListener("reset", () => {
      setTimeout(() => {
        profissionaisCarregados = false;

        preencherSelect(selectProfissional, optionPadrao("Selecione o profissional"));
        limparServicos();

        if (abortServicos) {
          abortServicos.abort();
          abortServicos = null;
        }
      }, 0);
    });
  }

  if (modal) {
    const observer = new MutationObserver(() => {
      const aberto =
        modal.classList.contains("ativo") ||
        modal.classList.contains("open") ||
        modal.getAttribute("aria-hidden") === "false";

      if (aberto) {
        carregarProfissionais();
      } else {
        limparServicos();

        if (abortServicos) {
          abortServicos.abort();
          abortServicos = null;
        }
      }
    });

    observer.observe(modal, {
      attributes: true,
      attributeFilter: ["class", "aria-hidden"]
    });

    const abertoAoCarregar =
      modal.classList.contains("ativo") ||
      modal.classList.contains("open") ||
      modal.getAttribute("aria-hidden") === "false";

    if (abertoAoCarregar) {
      carregarProfissionais();
    }
  } else {
    carregarProfissionais();
  }
})();
