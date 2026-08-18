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
    horarios: $("ed_ag_horarios_disponiveis"), salvar: $("btnSalvarEdicaoAgendamento")
  };

  let diasAtendimento = [];
  let mesAtual = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  let carregandoRegistro = false;
  let semanaRecorrente = null;
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
    box.className = `ui-alert ${tipo === "success" ? "ui-alert--success" : "ui-alert--danger"}`;
    box.innerHTML = `<div class="ui-alert__icon">${tipo === "success" ? "✓" : "×"}</div><div class="ui-alert__content"><p class="ui-alert__title">${tipo === "success" ? "Sucesso" : "Atenção"}</p><div class="ui-alert__msg"></div></div>`;
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

  async function carregarGrade(data, horaAtual = "") {
    limparHorario("Carregando horários disponíveis...");
    try {
      const j = await api("agenda/horarios-disponiveis", { id_profissional: el.profissional.value, id_servico: el.servico.value, data, id_agendamento: el.id.value });
      diasAtendimento = j.data?.dias_atendimento || []; renderCalendario();
      const horarios = j.data?.horarios || [];
      if (!horarios.length) { limparHorario("Nenhum horário disponível para esta data."); return; }
      el.hora.innerHTML = '<option value="">Selecione o horário</option>' + horarios.map(h => `<option value="${esc(h.hora_inicio)}" data-hora-fim="${esc(h.hora_fim)}">${esc(h.hora_inicio)} às ${esc(h.hora_fim)}</option>`).join("");
      el.hora.disabled = false; el.hora.value = horaAtual.slice(0,5);
      el.horarios.innerHTML = horarios.map(h => `<button type="button" class="ag-horario-opcao ${h.hora_inicio === horaAtual.slice(0,5) ? "selecionado" : ""}" data-hora="${esc(h.hora_inicio)}">${esc(h.hora_inicio)}</button>`).join("");
    } catch (e) { limparHorario(e.message); }
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

  function atualizarRecorrencia() { const ativo = el.repetir.checked; el.recorrenciaBox.hidden = !ativo; el.recorrenciaFim.required = ativo; if (!ativo) el.recorrenciaFim.value = ""; }

  async function abrirEdicao(id) {
    if (!id || carregandoRegistro) return;
    carregandoRegistro = true;
    const conteudoModal = document.querySelector("#modalEditarAgendamento .modal-conteudo");
    if (conteudoModal) conteudoModal.scrollTop = 0;
    try {
      const j = await api("agenda/agendamento/detalhar", { id_agendamento: id }); const a = j.data;
      el.id.value = a.id_agendamento; el.status.value = a.status; el.obs.value = a.observacao || "";
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
      await carregarServicos(a.id_servico);
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
    setTimeout(() => abrirEdicao(id), 0);
  });
  el.profissional.addEventListener("change", async () => { el.disponibilidade.hidden = true; el.data.value = ""; limparHorario(); await carregarServicos(); });
  el.servico.addEventListener("change", async () => {
    el.data.value = ""; limparHorario(); el.disponibilidade.hidden = !el.servico.value;
    const op = el.servico.options[el.servico.selectedIndex]; el.duracao.value = op?.dataset.duracao || "";
    if (el.servico.value) { try { const j = await api("agenda/horarios-disponiveis", { id_profissional: el.profissional.value, id_servico: el.servico.value, data: hoje(), id_agendamento: el.id.value }); diasAtendimento = j.data?.dias_atendimento || []; el.instrucao.textContent = "Os dias destacados estão disponíveis para agendamento, incluindo hoje enquanto houver horários futuros."; renderCalendario(); } catch(e) { toast("danger", e.message); } }
  });
  el.dias.addEventListener("click", (ev) => { const b = ev.target.closest("button.disponivel"); if (!b) return; el.data.value = b.dataset.data; el.dataTexto.textContent = new Date(`${b.dataset.data}T12:00:00`).toLocaleDateString("pt-BR", {weekday:"long",day:"2-digit",month:"long"}); renderCalendario(); carregarGrade(b.dataset.data); });
  el.horarios.addEventListener("click", (ev) => { const b = ev.target.closest(".ag-horario-opcao"); if (!b) return; el.hora.value = b.dataset.hora; el.horarios.querySelectorAll("button").forEach(x => x.classList.toggle("selecionado", x === b)); });
  el.anterior.addEventListener("click", () => { mesAtual = new Date(mesAtual.getFullYear(), mesAtual.getMonth()-1, 1); renderCalendario(); });
  el.proximo.addEventListener("click", () => { mesAtual = new Date(mesAtual.getFullYear(), mesAtual.getMonth()+1, 1); renderCalendario(); });
  el.repetir.addEventListener("change", atualizarRecorrencia);

  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    if (!el.id.value || !el.cliente.value || !el.profissional.value || !el.servico.value || !el.data.value || !el.hora.value) { toast("danger", "Preencha cliente, profissional, serviço, data e horário."); return; }
    const body = new FormData(form); body.set("repetir_semanalmente", el.repetir.checked ? "1" : "0");
    el.salvar.disabled = true; const original = el.salvar.textContent; el.salvar.textContent = "Salvando...";
    try {
      const j = await api("agenda/agendamento/editar", {}, { method:"POST", body });
      toast("success", j.user_msg || "Agendamento atualizado com sucesso.");
      document.dispatchEvent(new CustomEvent("agenda:agendamento:atualizado", { detail:j.data }));
      setTimeout(() => { window.fecharModal?.("modalEditarAgendamento"); window.location.reload(); }, 1200);
    } catch(e) { toast("danger", e.message); } finally { el.salvar.disabled = false; el.salvar.textContent = original; }
  });
})();
