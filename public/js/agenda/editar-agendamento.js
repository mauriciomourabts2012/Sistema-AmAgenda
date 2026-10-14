// Carrega e atualiza o modal Editar Agendamento com a mesma agenda visual do cadastro.
(() => {
  "use strict";

  const $ = (id) => document.getElementById(id);
  const form = $("formEditarAgendamento");
  if (!form) return;

  const el = {
    id: $("ed_ag_id"), cliente: $("ed_ag_cliente_id"), clienteBusca: $("ed_ag_cliente_busca"), telefone: $("ed_ag_cliente_tel"),
    profissional: $("ed_ag_profissional"), servico: $("ed_ag_servico"), data: $("ed_ag_data"),
    hora: $("ed_ag_hora"), duracao: $("ed_ag_duracao"), status: $("ed_ag_status"),
    obs: $("ed_ag_obs"), repetir: $("ed_ag_repetir_semanal"), recorrenciaBox: $("ed_ag_recorrencia_config"),
    recorrenciaPreview: $("ed_ag_repetir_preview"),
    recorrenciaFim: $("ed_ag_recorrencia_data_fim"), disponibilidade: $("ed_ag_disponibilidade"),
    instrucao: $("ed_ag_disponibilidade_instrucao"), dias: $("ed_ag_calendario_dias"),
    mes: $("ed_ag_calendario_mes"), anterior: $("ed_ag_calendario_anterior"),
    proximo: $("ed_ag_calendario_proximo"), dataTexto: $("ed_ag_data_selecionada"),
    horarios: $("ed_ag_horarios_disponiveis"), salvar: $("btnSalvarEdicaoAgendamento"),
    statusAjuda: $("ed_ag_status_ajuda"), statusTexto: $("ed_ag_status_texto"), statusExibicao: $("ed_ag_status_exibicao"),
    situacao: $("ed_ag_situacao"), situacaoIcone: $("ed_ag_situacao_icone"),
    situacaoStatus: $("ed_ag_situacao_status"), situacaoTitulo: $("ed_ag_situacao_titulo"),
    situacaoQuando: $("ed_ag_situacao_quando"), situacaoEtapas: $("ed_ag_situacao_etapas"),
    situacaoDestaque: $("ed_ag_situacao_destaque"), situacaoMensagem: $("ed_ag_situacao_mensagem"),
    acoes: $("ed_ag_acoes"), acoesLista: $("ed_ag_acoes_lista")
  };

  // Ciclo de vida exibido no modal. É somente orientação: editar_agendamento.php valida
  // novamente a transição, o horário e as permissões sobre o registro travado.
  const ROTULOS_STATUS = { pendente: "Pendente", confirmado: "Confirmado", concluido: "Concluído", cancelado: "Cancelado", faltou: "Faltou" };
  const STATUS_FINAIS = ["concluido", "cancelado", "faltou"];
  const TRANSICOES_STATUS = { pendente: ["pendente", "confirmado", "cancelado"], confirmado: ["confirmado", "concluido", "faltou", "cancelado"] };
  const CODIGO_NOTIFICACAO_FINALIZACAO = "agenda.agendamento_finalizacao_pendente";
  const ACOES_STATUS = {
    confirmado: { acao: "confirmar", classe: "ed-acao--confirmar", icone: "fa-solid fa-calendar-check", titulo: "Confirmar agendamento", descricao: "Marcar este agendamento como confirmado" },
    concluido: { acao: "concluir", classe: "ed-acao--concluir", icone: "fa-solid fa-check", titulo: "Concluir atendimento", descricao: "Registrar que o serviço foi realizado" },
    faltou: { acao: "faltou", classe: "ed-acao--faltou", icone: "fa-solid fa-user-xmark", titulo: "Registrar falta", descricao: "Cliente não compareceu" },
    cancelado: { acao: "cancelar", classe: "ed-acao--cancelar", icone: "fa-solid fa-ban", titulo: "Cancelar agendamento", descricao: "Cancelar este agendamento" }
  };
  let registroAtual = null;
  let contextoAtual = {};

  let diasAtendimento = [];
  let mesAtual = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  let carregandoRegistro = false;
  let semanaRecorrente = null;
  let horarioOriginal = null; // data/hora do registro aberto (o backend confere contra o banco)
  const texto = (v) => String(v ?? "").trim();
  const lista = (j) => Array.isArray(j?.data?.items) ? j.data.items : Array.isArray(j?.data?.servicos) ? j.data.servicos : Array.isArray(j?.data?.profissionais) ? j.data.profissionais : Array.isArray(j?.data) ? j.data : [];
  const hoje = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}`; };
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}`;
  const esc = (s) => texto(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");

  // Uma ocorrência recorrente pode mudar de dia somente dentro da própria semana.
  // As demais linhas do grupo de recorrência não são alteradas por esta edição.
  function intervaloDaSemana(dataIso) {
    const referencia = new Date(`${dataIso}T12:00:00`);
    const deslocamento = (referencia.getDay() + 6) % 7;
    const inicio = new Date(referencia); inicio.setDate(referencia.getDate() - deslocamento);
    const fim = new Date(inicio); fim.setDate(inicio.getDate() + 6);
    return { inicio: iso(inicio), fim: iso(fim) };
  }

  async function api(path, params = {}, options = {}) {
    const qs = new URLSearchParams({ path, ...params });
    const r = await fetch(`/public/api/api_central.php?${qs}`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" }, ...options });
    const j = await r.json().catch(() => null);
    if (!r.ok || !j || j.ok === false) throw new Error(j?.user_msg || "Não foi possível concluir a operação.");
    return j;
  }

  function toast(tipo, msg) {
    let stack = document.querySelector(".ui-toast-stack");
    if (!stack) { stack = document.createElement("div"); stack.className = "ui-toast-stack"; document.body.appendChild(stack); }
    const box = document.createElement("div");
    box.className = `ui-alert ${tipo === "success" ? "ui-alert--success" : tipo === "warning" ? "ui-alert--warning" : "ui-alert--danger"}`;
    box.innerHTML = `<div class="ui-alert__icon">${tipo === "success" ? "✓" : tipo === "warning" ? "!" : "×"}</div><div class="ui-alert__content"><p class="ui-alert__title">${tipo === "success" ? "Sucesso" : "Atenção"}</p><div class="ui-alert__msg"></div></div>`;
    box.querySelector(".ui-alert__msg").textContent = msg; stack.appendChild(box); setTimeout(() => box.remove(), 4500);
  }

  function renderCalendario() {
    const nomes = ["Janeiro","Fevereiro","Março","Abril","Maio","Junho","Julho","Agosto","Setembro","Outubro","Novembro","Dezembro"];
    el.mes.textContent = `${nomes[mesAtual.getMonth()]} ${mesAtual.getFullYear()}`;
    const inicio = new Date(mesAtual.getFullYear(), mesAtual.getMonth(), 1);
    const deslocamento = (inicio.getDay() + 6) % 7;
    const total = new Date(mesAtual.getFullYear(), mesAtual.getMonth()+1, 0).getDate();
    let html = "";
    for (let i=0; i<deslocamento; i++) html += '<span class="ag-calendario-dia vazio"></span>';
    const mapa = ["domingo","segunda","terca","quarta","quinta","sexta","sabado"];
    for (let n=1; n<=total; n++) {
      const d = new Date(mesAtual.getFullYear(), mesAtual.getMonth(), n);
      const valor = iso(d);
      const dentroDaSemana = !semanaRecorrente || (valor >= semanaRecorrente.inicio && valor <= semanaRecorrente.fim);
      const disponivel = valor >= hoje() && dentroDaSemana && diasAtendimento.includes(mapa[d.getDay()]);
      const selecionado = valor === el.data.value;
      html += `<button type="button" class="ag-calendario-dia ${disponivel ? "disponivel" : "indisponivel"} ${selecionado ? "selecionado" : ""}" data-data="${valor}" ${disponivel ? "" : "disabled"}>${n}</button>`;
    }
    el.dias.innerHTML = html;
  }

  function limparHorario(msg = "Escolha no calendário um dos dias destacados.") {
    el.hora.innerHTML = '<option value="">Nenhum horário selecionado</option>'; el.hora.disabled = true;
    el.horarios.innerHTML = `<span class="ag-disponibilidade-vazio">${esc(msg)}</span>`;
  }

  // Agendamento que já começou: mantém somente o horário original para permitir
  // alterar apenas o status. Reagendar continua exigindo um horário disponível.
  function inicioJaPassou(data, hora) {
    const inicio = new Date(`${texto(data)}T${texto(hora).slice(0,5)}:00`);
    return !Number.isNaN(inicio.getTime()) && inicio <= new Date();
  }

  // Registro já iniciado cujo profissional/serviço não está mais entre os ativos:
  // mantém o valor original selecionado só para permitir alterar o status.
  function manterOpcaoOriginal(select, valor, rotulo) {
    const v = texto(valor);
    if (!v || select.value === v) return false;
    const opcao = document.createElement("option"); opcao.value = v; opcao.textContent = rotulo;
    select.appendChild(opcao); select.value = v;
    return true;
  }

  function manterHorarioOriginal(data, horaAtual) {
    const hora = String(horaAtual || "").slice(0,5);
    if (!horarioOriginal || data !== horarioOriginal.data || hora !== horarioOriginal.hora) return false;
    if (!inicioJaPassou(data, hora)) return false;
    el.hora.innerHTML = `<option value="${esc(hora)}">${esc(hora)}</option>`; el.hora.disabled = false; el.hora.value = hora;
    el.horarios.innerHTML = `<button type="button" class="ag-horario-opcao selecionado" data-hora="${esc(hora)}">${esc(hora)}</button>`;
    return true;
  }

  async function carregarGrade(data, horaAtual = "") {
    limparHorario("Carregando horários disponíveis...");
    try {
      const j = await api("agenda/horarios-disponiveis", { id_profissional: el.profissional.value, id_servico: el.servico.value, duracao: el.duracao.value, data, id_agendamento: el.id.value });
      diasAtendimento = j.data?.dias_atendimento || []; renderCalendario();
      if (manterHorarioOriginal(data, horaAtual)) return;
      const horarios = j.data?.horarios || [];
      if (!horarios.length) { limparHorario("Nenhum horário disponível para esta data."); return; }
      el.hora.innerHTML = '<option value="">Selecione o horário</option>' + horarios.map(h => `<option value="${esc(h.hora_inicio)}" data-hora-fim="${esc(h.hora_fim)}">${esc(h.hora_inicio)} às ${esc(h.hora_fim)}</option>`).join("");
      el.hora.disabled = false; el.hora.value = horaAtual.slice(0,5);
      el.horarios.innerHTML = horarios.map(h => `<button type="button" class="ag-horario-opcao ${h.hora_inicio === horaAtual.slice(0,5) ? "selecionado" : ""}" data-hora="${esc(h.hora_inicio)}">${esc(h.hora_inicio)}</button>`).join("");
    } catch (e) { if (!manterHorarioOriginal(data, horaAtual)) limparHorario(e.message); }
  }

  async function carregarProfissionais(selecionar = "") {
    const j = await api("agenda/profissional-modal-novo-agendamento/listar", { status: "ativo", limite: "500" });
    el.profissional.innerHTML = '<option value="">Selecione o profissional</option>' + lista(j).map(p => `<option value="${esc(p.id_profissional ?? p.id)}">${esc(p.nome ?? p.nome_completo)}${p.especialidade ? ` - ${esc(p.especialidade)}` : ""}</option>`).join("");
    el.profissional.value = texto(selecionar);
  }

  async function carregarServicos(selecionar = "") {
    if (!el.profissional.value) { el.servico.innerHTML = '<option value="">Selecione o profissional primeiro</option>'; return; }
    const j = await api("agenda/servico-profissional/listar", { id_profissional: el.profissional.value, status: "ativo", limite: "500" });
    el.servico.innerHTML = '<option value="">Selecione o serviço</option>' + lista(j).map(s => `<option value="${esc(s.id_servico ?? s.id)}" data-duracao="${esc(s.duracao_min)}">${esc(s.nome)}</option>`).join("");
    el.servico.value = texto(selecionar);
  }

  async function carregarClientes(selecionar = "", nome = "", tel = "") {
    el.cliente.value = texto(selecionar);
    if (el.clienteBusca) el.clienteBusca.value = texto(nome);
    el.telefone.value = texto(tel);
  }

  function normalizarStatus(valor) {
    const status = texto(valor).toLowerCase();
    return Object.prototype.hasOwnProperty.call(ROTULOS_STATUS, status) ? status : "";
  }

  function dataHoraLocal(data, hora) {
    const valor = new Date(`${texto(data)}T${texto(hora).slice(0,5)}:00`);
    return Number.isNaN(valor.getTime()) ? null : valor;
  }

  function podeEditar() {
    return typeof window.usuarioPode === "function" && window.usuarioPode("agenda.editar_agendamento") === true;
  }

  function podeCancelar() {
    return typeof window.usuarioPode === "function" && window.usuarioPode("agenda.cancelar_agendamento") === true;
  }

  // Status atual e destinos permitidos; Concluído/Faltou ficam indisponíveis antes do início.
  function opcoesStatus(a, agora = new Date()) {
    const atual = normalizarStatus(a?.status);
    if (!TRANSICOES_STATUS[atual]) return [{ valor: atual, desabilitada: false }];
    const inicio = dataHoraLocal(a.data_agendamento, a.hora_inicio);
    const iniciou = inicio !== null && inicio <= agora;
    return TRANSICOES_STATUS[atual]
      .filter((status) => status !== "cancelado" || podeCancelar())
      .map((status) => ({ valor: status, desabilitada: (status === "concluido" || status === "faltou") && !iniciou }));
  }

  // Ações do painel: as mesmas transições das ações rápidas do menu de três pontos.
  function acoesDisponiveis(a) {
    if (!a || !podeEditar()) return [];
    const atual = normalizarStatus(a.status);
    return opcoesStatus(a)
      .filter((opcao) => opcao.valor !== atual && ACOES_STATUS[opcao.valor])
      .map((opcao) => ({ ...ACOES_STATUS[opcao.valor], desabilitada: opcao.desabilitada }));
  }

  // Os botões não têm lógica própria: data-acao-rapida + data-id-agendamento são tratados pelo
  // fluxo existente (lista-agenda.js), com confirmação, detalhamento, lock, auditoria e notificações.
  function renderAcoes(a) {
    if (!el.acoes || !el.acoesLista) return;
    const acoes = acoesDisponiveis(a);
    const id = esc(a?.id_agendamento);
    const hora = esc(texto(a?.hora_inicio).slice(0,5));
    el.acoesLista.innerHTML = acoes.map((acao) => `
      <button type="button" class="ed-acao ${acao.classe}" data-acao-rapida="${acao.acao}" data-id-agendamento="${id}"${acao.desabilitada ? " disabled" : ""}>
        <span class="ed-acao-icone" aria-hidden="true"><i class="${acao.icone}"></i></span>
        <span class="ed-acao-texto"><strong>${acao.titulo}</strong><small>${acao.desabilitada ? `Disponível após o início do atendimento (${hora})` : acao.descricao}</small></span>
      </button>`).join("");
    el.acoes.hidden = acoes.length === 0;
    form.classList.toggle("ed-form--com-acoes", acoes.length > 0);
  }

  // Status somente leitura: o valor oculto mantém o status atual exigido pela API.
  function aplicarCampoStatus(a) {
    const atual = normalizarStatus(a?.status);
    el.status.value = atual;
    if (el.statusTexto) el.statusTexto.textContent = `${ROTULOS_STATUS[atual] || texto(a?.status) || "—"} (definido pelo sistema)`;
    if (el.statusExibicao) el.statusExibicao.dataset.status = atual;
    const ajuda = el.statusAjuda?.querySelector("span");
    if (ajuda) {
      ajuda.textContent = STATUS_FINAIS.includes(atual)
        ? "Agendamento finalizado. O status não pode ser alterado."
        : "O status é controlado pelas ações acima e não pode ser alterado manualmente.";
    }
  }

  // Áreas do formulário além do Status; inert impede a interação sem retirar os valores do envio.
  function areasEditaveis() {
    const campoStatus = el.status.closest(".modal-campo");
    return [...form.querySelectorAll(".modal-grid > .modal-campo, .modal-grid > .ag-disponibilidade")].filter((area) => area !== campoStatus);
  }

  function bloquearAreas(areas, bloquear) {
    areas.forEach((area) => {
      area.inert = bloquear;
      area.classList.toggle("ed-area-bloqueada", bloquear);
    });
  }

  // Concluído, Cancelado e Faltou: modal somente para consulta, sem Salvar.
  function aplicarModoConsulta(finalizado) {
    form.classList.toggle("ed-form--consulta", finalizado);
    bloquearAreas(areasEditaveis(), finalizado);
    const botaoSalvar = el.salvar;
    botaoSalvar.hidden = finalizado;
    botaoSalvar.closest(".modal-acoes")?.classList.toggle("ed-acoes-rodape--consulta", finalizado);
    const botaoFechar = form.querySelector(".ed-botao-cancelar");
    if (botaoFechar) botaoFechar.textContent = finalizado ? "Fechar" : "Cancelar";
  }

  function descreverSituacao(a, codigoNotificacao, agora = new Date()) {
    const status = normalizarStatus(a?.status);
    const horaFim = texto(a?.hora_fim).slice(0,5);
    const fim = dataHoraLocal(a?.data_agendamento, a?.hora_fim);
    const consulta = "Este registro está disponível somente para consulta.";
    if (status === "pendente") return { titulo: "Aguardando confirmação", destaque: "Este agendamento aguarda confirmação.", mensagem: "Confirme ou cancele usando as ações disponíveis.", selo: "fa-solid fa-hourglass-half" };
    if (status === "confirmado" && fim !== null && fim <= agora) {
      const daNotificacao = codigoNotificacao === CODIGO_NOTIFICACAO_FINALIZACAO;
      return {
        titulo: "Aguardando resultado",
        destaque: daNotificacao ? `Notificação de finalização: o horário previsto terminou às ${horaFim}.` : `O horário previsto terminou às ${horaFim}.`,
        mensagem: "Registre o resultado do atendimento: concluir, falta ou cancelar.",
        selo: "fa-solid fa-bell", alerta: true, notificacao: daNotificacao
      };
    }
    if (status === "confirmado") return { titulo: "Atendimento confirmado", destaque: "Este agendamento está confirmado.", mensagem: "Após o início do atendimento, registre o resultado: concluir, falta ou cancelar.", selo: "fa-solid fa-check" };
    if (status === "concluido") return { titulo: "Atendimento concluído", destaque: "Agendamento finalizado.", mensagem: consulta, selo: "fa-solid fa-check-double" };
    if (status === "cancelado") return { titulo: "Agendamento cancelado", destaque: "Agendamento finalizado.", mensagem: consulta, selo: "fa-solid fa-xmark" };
    if (status === "faltou") return { titulo: "Cliente não compareceu", destaque: "Agendamento finalizado.", mensagem: consulta, selo: "fa-solid fa-user-xmark" };
    return { titulo: "", destaque: "", mensagem: "", selo: "fa-solid fa-circle" };
  }

  // Concluído, Faltou e Cancelado são resultados alternativos depois da confirmação (ou de Pendente, no cancelamento).
  function estadoEtapa(etapa, status) {
    if (etapa === "resultado") return STATUS_FINAIS.includes(status) ? "definido" : "";
    if (etapa === status) return "atual";
    if (etapa === "pendente" && status !== "pendente") return "feito";
    if (etapa === "confirmado" && (status === "concluido" || status === "faltou")) return "feito";
    return "";
  }

  function textoQuando(a) {
    const data = dataHoraLocal(a?.data_agendamento, "12:00");
    let dia = texto(a?.data_agendamento);
    if (data) {
      const semana = data.toLocaleDateString("pt-BR", { weekday: "short" }).replace(".", "");
      dia = `${semana.charAt(0).toUpperCase()}${semana.slice(1)}, ${data.toLocaleDateString("pt-BR", { day: "2-digit", month: "2-digit", year: "numeric" })}`;
    }
    const duracao = Number(a?.duracao_min_aplicada) > 0 ? `  (${Number(a.duracao_min_aplicada)} min)` : "";
    return `${dia}  ·  ${texto(a?.hora_inicio).slice(0,5)} às ${texto(a?.hora_fim).slice(0,5)}${duracao}`;
  }

  function renderSituacao(a, codigoNotificacao) {
    if (!el.situacao) return;
    const status = normalizarStatus(a?.status);
    const situacao = descreverSituacao(a, codigoNotificacao);
    el.situacao.dataset.status = status;
    el.situacao.classList.toggle("ed-situacao--alerta", situacao.alerta === true);
    el.situacao.classList.toggle("ed-situacao--notificacao", situacao.notificacao === true);
    el.situacaoIcone.className = situacao.selo;
    el.situacaoStatus.textContent = ROTULOS_STATUS[status] || texto(a?.status) || "—";
    el.situacaoTitulo.textContent = situacao.titulo;
    if (el.situacaoDestaque) el.situacaoDestaque.textContent = situacao.destaque;
    el.situacaoMensagem.textContent = situacao.mensagem;
    el.situacaoQuando.textContent = textoQuando(a);
    el.situacaoEtapas.querySelectorAll("li[data-etapa]").forEach((item) => {
      const estado = estadoEtapa(item.dataset.etapa, status);
      item.dataset.estado = estado;
      if (estado === "atual") item.setAttribute("aria-current", "step"); else item.removeAttribute("aria-current");
    });
    el.situacao.hidden = false;
  }

  function limparEstadoCicloVida() {
    registroAtual = null;
    form.classList.remove("ed-form--consulta", "ed-form--com-acoes");
    bloquearAreas(areasEditaveis(), false);
    el.salvar.hidden = false;
    el.salvar.closest(".modal-acoes")?.classList.remove("ed-acoes-rodape--consulta");
    const botaoFechar = form.querySelector(".ed-botao-cancelar");
    if (botaoFechar) botaoFechar.textContent = "Cancelar";
    if (el.acoesLista) el.acoesLista.innerHTML = "";
    if (el.acoes) el.acoes.hidden = true;
    if (el.situacao) el.situacao.hidden = true;
  }

  function atualizarRecorrencia() { const ativo = el.repetir.checked; el.recorrenciaBox.hidden = !ativo; el.recorrenciaFim.required = ativo; if (!ativo) el.recorrenciaFim.value = ""; }

  async function abrirEdicao(id, contexto = {}) {
    if (!id || carregandoRegistro) return;
    carregandoRegistro = true;
    limparEstadoCicloVida();
    contextoAtual = contexto || {};
    const conteudoModal = document.querySelector("#modalEditarAgendamento .modal-conteudo");
    if (conteudoModal) conteudoModal.scrollTop = 0;
    try {
      const j = await api("agenda/agendamento/detalhar", { id_agendamento: id }); const a = j.data;
      horarioOriginal = { data: texto(a.data_agendamento), hora: texto(a.hora_inicio).slice(0,5) };
      el.id.value = a.id_agendamento; el.obs.value = a.observacao || "";
      registroAtual = a;
      renderSituacao(a, texto(contexto.codigoNotificacao));
      aplicarCampoStatus(a);
      aplicarModoConsulta(STATUS_FINAIS.includes(normalizarStatus(a.status)));
      renderAcoes(a);
      semanaRecorrente = texto(a.grupo_recorrencia) ? intervaloDaSemana(a.data_agendamento) : null;
      el.repetir.checked = Number(a.repetir_semanalmente) === 1; el.recorrenciaFim.value = a.recorrencia_data_fim || ""; atualizarRecorrencia();
      el.repetir.disabled = Boolean(semanaRecorrente);
      el.recorrenciaFim.disabled = Boolean(semanaRecorrente);
      if (el.recorrenciaPreview) {
        el.recorrenciaPreview.style.display = semanaRecorrente ? "block" : "none";
        el.recorrenciaPreview.textContent = semanaRecorrente
          ? "Você está editando somente esta ocorrência. As outras semanas permanecerão iguais."
          : "";
      }
      await Promise.all([carregarClientes(a.id_cliente, a.cliente_nome, a.cliente_telefone), carregarProfissionais(a.id_profissional)]);
      const registroIniciado = inicioJaPassou(a.data_agendamento, a.hora_inicio);
      if (registroIniciado && manterOpcaoOriginal(el.profissional, a.id_profissional, "Profissional do agendamento (indisponível)")) {
        el.servico.innerHTML = ""; manterOpcaoOriginal(el.servico, a.id_servico, "Serviço do agendamento (indisponível)");
      } else {
        await carregarServicos(a.id_servico);
        if (registroIniciado) manterOpcaoOriginal(el.servico, a.id_servico, "Serviço do agendamento (indisponível)");
      }
      el.data.value = a.data_agendamento; mesAtual = new Date(`${a.data_agendamento}T12:00:00`); mesAtual = new Date(mesAtual.getFullYear(), mesAtual.getMonth(), 1);
      el.disponibilidade.hidden = false; el.duracao.value = texto(a.duracao_min_aplicada); renderCalendario();
      el.instrucao.textContent = semanaRecorrente
        ? "Esta ocorrência pode ser reagendada entre segunda e domingo desta semana, sem alterar as demais semanas."
        : "Os dias destacados estão disponíveis para reagendamento.";
      el.dataTexto.textContent = new Date(`${a.data_agendamento}T12:00:00`).toLocaleDateString("pt-BR", { weekday:"long", day:"2-digit", month:"long" });
      await carregarGrade(a.data_agendamento, a.hora_inicio);
    } catch (e) { toast("danger", e.message); } finally { carregandoRegistro = false; }
  }

  // lista-agenda.js informa o registro escolhido; os dados são confirmados pela API.
  document.addEventListener("agenda:editar:selecionado", (ev) => {
    const id = ev.detail?.id_agendamento || ev.detail?.agendamento?.id_agendamento || ev.detail?.agendamento?.id;
    // O código da notificação só ajusta a mensagem da situação; o registro vem sempre da API.
    const codigoNotificacao = texto(ev.detail?.codigo_notificacao);
    setTimeout(() => abrirEdicao(id, { codigoNotificacao }), 0);
  });
  el.profissional.addEventListener("change", async () => { el.disponibilidade.hidden = true; el.data.value = ""; limparHorario(); await carregarServicos(); });
  el.servico.addEventListener("change", async () => {
    el.data.value = ""; limparHorario(); el.disponibilidade.hidden = !el.servico.value;
    const op = el.servico.options[el.servico.selectedIndex]; el.duracao.value = op?.dataset.duracao || "";
    if (el.servico.value) { try { const j = await api("agenda/horarios-disponiveis", { id_profissional: el.profissional.value, id_servico: el.servico.value, duracao: el.duracao.value, data: hoje(), id_agendamento: el.id.value }); diasAtendimento = j.data?.dias_atendimento || []; el.instrucao.textContent = "Os dias destacados estão disponíveis para agendamento, incluindo hoje enquanto houver horários futuros."; renderCalendario(); } catch(e) { toast("danger", e.message); } }
  });
  el.duracao.addEventListener("change", async () => {
    el.data.value = ""; limparHorario();
    if (!el.servico.value || !el.duracao.value) return;
    try {
      const j = await api("agenda/horarios-disponiveis", { id_profissional: el.profissional.value, id_servico: el.servico.value, duracao: el.duracao.value, data: hoje(), id_agendamento: el.id.value });
      diasAtendimento = j.data?.dias_atendimento || []; renderCalendario();
    } catch (e) { toast("danger", e.message); }
  });
  el.dias.addEventListener("click", (ev) => { const b = ev.target.closest("button.disponivel"); if (!b) return; el.data.value = b.dataset.data; el.dataTexto.textContent = new Date(`${b.dataset.data}T12:00:00`).toLocaleDateString("pt-BR", {weekday:"long",day:"2-digit",month:"long"}); renderCalendario(); carregarGrade(b.dataset.data); });
  el.horarios.addEventListener("click", (ev) => { const b = ev.target.closest(".ag-horario-opcao"); if (!b) return; el.hora.value = b.dataset.hora; el.horarios.querySelectorAll("button").forEach(x => x.classList.toggle("selecionado", x === b)); });
  el.anterior.addEventListener("click", () => { mesAtual = new Date(mesAtual.getFullYear(), mesAtual.getMonth()-1, 1); renderCalendario(); });
  el.proximo.addEventListener("click", () => { mesAtual = new Date(mesAtual.getFullYear(), mesAtual.getMonth()+1, 1); renderCalendario(); });
  el.repetir.addEventListener("change", atualizarRecorrencia);
  // Duplo clique no painel: enquanto a confirmação visual estiver aberta, um novo clique não chega
  // ao fluxo das ações rápidas (que só bloqueia repetições após a confirmação).
  el.acoesLista?.addEventListener("click", (ev) => {
    if (document.querySelector(".ui-alert--confirm:not(.is-leaving)")) ev.stopPropagation();
  });
  // Ação concluída com sucesso (painel ou menu) sobre o agendamento aberto: o modal é recarregado
  // pelo detalhamento autorizado e passa a refletir o novo status.
  document.addEventListener("agenda:agendamento:status-atualizado", (ev) => {
    const id = texto(ev.detail?.id_agendamento);
    const modal = document.getElementById("modalEditarAgendamento");
    if (!id || id !== texto(el.id.value) || !modal?.classList.contains("ativo")) return;
    setTimeout(() => abrirEdicao(id, contextoAtual), 0);
  });
  // Ao fim do processamento, o fluxo das ações rápidas reabilita os botões; o painel volta a
  // refletir horário e permissões do registro aberto.
  document.addEventListener("agenda:agendamento:acao-rapida-estado", (ev) => {
    if (ev.detail?.carregando === true || texto(ev.detail?.id_agendamento) !== texto(el.id.value)) return;
    setTimeout(() => renderAcoes(registroAtual), 0);
  });

  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    if (registroAtual && STATUS_FINAIS.includes(normalizarStatus(registroAtual.status))) { toast("warning", "Este agendamento já foi finalizado e não pode ser alterado."); return; }
    if (!el.id.value || !el.cliente.value || !el.profissional.value || !el.servico.value || !el.duracao.value || !el.data.value || !el.hora.value) { toast("danger", "Preencha cliente, profissional, serviço, duração, data e horário."); return; }
    const body = new FormData(form); body.set("repetir_semanalmente", el.repetir.checked ? "1" : "0");
    el.salvar.disabled = true; const original = el.salvar.innerHTML; el.salvar.textContent = "Salvando...";
    try {
      const j = await api("agenda/agendamento/editar", {}, { method:"POST", body, headers: { Accept: "application/json", "X-CSRF-Token": String(window.__AUTH__?.csrf_token || "") } });
      toast("success", j.user_msg || "Agendamento atualizado com sucesso.");
      const avisosPlano = Array.isArray(j.data?.avisos_plano) ? j.data.avisos_plano : [];
      avisosPlano.forEach((aviso) => toast("warning", aviso?.mensagem || "O consumo mensal do plano está próximo do limite."));
      document.dispatchEvent(new CustomEvent("agenda:agendamento:atualizado", { detail:j.data }));
      window.CentroNotificacoes?.carregar?.(); // reflete a pendência resolvida/atualizada na central
      // Agenda aberta pelo Chat (origem=chat): volta para a conversa em vez de recarregar.
      const retornoChat = window.AgendaRetornoChat?.destino?.() || null;
      setTimeout(() => {
        window.fecharModal?.("modalEditarAgendamento");
        if (retornoChat) window.location.assign(retornoChat);
        else window.location.reload();
      }, avisosPlano.length ? 5200 : 1200);
    } catch(e) { toast("danger", e.message); } finally { el.salvar.disabled = false; el.salvar.innerHTML = original; }
  });
})();
