from io import BytesIO
from pathlib import Path

from pypdf import PdfReader, PdfWriter, Transformation
from pypdf._page import PageObject
from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas as pdf_canvas
from reportlab.platypus import (
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)

SOURCE = Path(r"C:\Users\mauri\Nextcloud2\ProjetosWEB\Sistema AmAgenda\MEMORIA_TECNICA_AMAGENDA_ATUALIZADA_2026-08-17.pdf")
SUPPLEMENT = Path(r"C:\xampp\htdocs\Sistema-AmAgenda\tmp\pdfs\suplemento_2026-08-18.pdf")
OUTPUT = Path(r"C:\Users\mauri\Nextcloud2\ProjetosWEB\Sistema AmAgenda\MEMORIA_TECNICA_AMAGENDA_ATUALIZADA_2026-08-18.pdf")

FONT = r"C:\Windows\Fonts\arial.ttf"
FONT_BOLD = r"C:\Windows\Fonts\arialbd.ttf"
pdfmetrics.registerFont(TTFont("AmArial", FONT))
pdfmetrics.registerFont(TTFont("AmArialBold", FONT_BOLD))

BLUE = colors.HexColor("#0B63E5")
NAVY = colors.HexColor("#101A31")
MUTED = colors.HexColor("#60708A")
PALE = colors.HexColor("#EEF5FF")
BORDER = colors.HexColor("#DCE6F3")
GREEN = colors.HexColor("#0C8A55")

styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name="DocTitle", fontName="AmArialBold", fontSize=24, leading=29, textColor=NAVY, spaceAfter=7 * mm))
styles.add(ParagraphStyle(name="DocSub", fontName="AmArial", fontSize=11, leading=16, textColor=MUTED, spaceAfter=7 * mm))
styles.add(ParagraphStyle(name="H1x", fontName="AmArialBold", fontSize=16, leading=20, textColor=NAVY, spaceBefore=2 * mm, spaceAfter=4 * mm))
styles.add(ParagraphStyle(name="H2x", fontName="AmArialBold", fontSize=11.5, leading=15, textColor=BLUE, spaceBefore=2 * mm, spaceAfter=2 * mm))
styles.add(ParagraphStyle(name="Bodyx", fontName="AmArial", fontSize=9.2, leading=13.2, textColor=NAVY, spaceAfter=2.2 * mm))
styles.add(ParagraphStyle(name="Bulletx", fontName="AmArial", fontSize=9, leading=12.5, textColor=NAVY, leftIndent=5 * mm, firstLineIndent=-3.5 * mm, bulletIndent=0, spaceAfter=1.4 * mm))
styles.add(ParagraphStyle(name="Smallx", fontName="AmArial", fontSize=8, leading=11, textColor=MUTED))
styles.add(ParagraphStyle(name="CardTitle", fontName="AmArialBold", fontSize=10.5, leading=13, textColor=NAVY, spaceAfter=1.5 * mm))
styles.add(ParagraphStyle(name="CardBody", fontName="AmArial", fontSize=8.4, leading=11.5, textColor=MUTED))


def header_footer(canvas, doc):
    canvas.saveState()
    w, h = A4
    canvas.setFillColor(BLUE)
    canvas.rect(0, h - 5 * mm, w, 5 * mm, fill=1, stroke=0)
    canvas.setFont("AmArialBold", 8)
    canvas.setFillColor(NAVY)
    canvas.drawString(18 * mm, h - 14 * mm, "AmAgenda - Memoria tecnica")
    canvas.setFont("AmArial", 8)
    canvas.setFillColor(MUTED)
    canvas.drawRightString(w - 18 * mm, h - 14 * mm, "Atualizacao tecnica de 18/08/2026")
    canvas.setStrokeColor(BORDER)
    canvas.line(18 * mm, 16 * mm, w - 18 * mm, 16 * mm)
    canvas.setFont("AmArial", 7.5)
    canvas.drawString(18 * mm, 10.5 * mm, "Suplemento tecnico - ajustes relevantes do dia")
    canvas.drawRightString(w - 18 * mm, 10.5 * mm, f"pagina {doc.page}")
    canvas.restoreState()


doc = SimpleDocTemplate(
    str(SUPPLEMENT), pagesize=A4,
    leftMargin=18 * mm, rightMargin=18 * mm,
    topMargin=22 * mm, bottomMargin=20 * mm,
    title="Atualizacao tecnica AmAgenda - 18/08/2026",
    author="AmAgenda",
)


def p(text, style="Bodyx"):
    return Paragraph(text, styles[style])


def bullets(items):
    return [p(f"• {item}", "Bulletx") for item in items]


story = []
story += [Spacer(1, 9 * mm), p("Atualizacao tecnica<br/>18 de agosto de 2026", "DocTitle")]
story += [p("Consolidacao dos ajustes funcionais e visuais relevantes realizados no AmAgenda. Esta atualizacao complementa, sem substituir, a memoria tecnica de 17/08/2026.", "DocSub")]

cards = [
    ("Permissoes", "Perfil Proprietario reconhecido dinamicamente e Super Admin com acesso integral no modo de suporte."),
    ("Agenda", "Listagem por empresa corrigida, fluxo de agendamento preservado e novos agendamentos confirmados por padrao."),
    ("Resumo do dia", "Indicadores reais, ocupacao semanal, profissionais e proximos atendimentos reorganizados."),
    ("Login web", "Experiencia desktop ampla em 60/40, painel visual institucional e bloco de acesso compacto."),
]
card_rows = []
for i in range(0, len(cards), 2):
    row = []
    for title, body in cards[i:i + 2]:
        row.append([p(title, "CardTitle"), p(body, "CardBody")])
    card_rows.append(row)
table = Table(card_rows, colWidths=[84 * mm, 84 * mm], hAlign="LEFT")
table.setStyle(TableStyle([
    ("BACKGROUND", (0, 0), (-1, -1), PALE),
    ("BOX", (0, 0), (-1, -1), 0.7, BORDER),
    ("INNERGRID", (0, 0), (-1, -1), 4, colors.white),
    ("VALIGN", (0, 0), (-1, -1), "TOP"),
    ("LEFTPADDING", (0, 0), (-1, -1), 10),
    ("RIGHTPADDING", (0, 0), (-1, -1), 10),
    ("TOPPADDING", (0, 0), (-1, -1), 10),
    ("BOTTOMPADDING", (0, 0), (-1, -1), 10),
]))
story += [table, Spacer(1, 8 * mm)]
story += [p("Escopo e principios preservados", "H1x")]
story += bullets([
    "Arquitetura de login, sessao, APIs e redirecionamentos foi preservada.",
    "As autorizacoes passaram a considerar o perfil real do usuario, sem depender de um usuario proprietario fixo.",
    "A separacao por empresa continua obrigatoria; Super Admin opera com acesso controlado no contexto selecionado.",
    "Identidade Visual continua fornecendo nome, logotipo e imagem de login por empresa, com fallback oficial AmAgenda.",
])
story += [PageBreak(), Spacer(1, 12 * mm)]

story += [p("20. Autenticacao, sessao e permissoes", "H1x")]
story += [p("20.1 Proprietario por perfil", "H2x")]
story += [p("A permissao de proprietario passou a ser derivada do perfil associado ao usuario autenticado. Assim, novos usuarios criados com perfil Proprietario recebem as mesmas autorizacoes previstas para esse papel, sem vinculo com um ID de usuario especifico.")]
story += bullets([
    "Sessao normaliza perfil_id, perfil_nome, tipo_usuario, empresa_id e modo_suporte.",
    "Identidade Visual aceita Proprietario e Super Admin em modo de suporte.",
    "As mensagens de bloqueio permanecem para perfis sem autorizacao.",
])
story += [p("20.2 Super Admin", "H2x")]
story += [p("Super Admin deve funcionar como autoridade total do sistema. Ao acessar uma empresa em modo de suporte, pode consultar a Agenda, visualizar seus agendamentos e administrar a Identidade Visual, sempre dentro do contexto da empresa selecionada.")]
story += bullets([
    "A rota de listagem da Agenda reconhece o modo de suporte do Super Admin.",
    "O frontend nao bloqueia o Super Admin por exigir exclusivamente o perfil Proprietario.",
    "O acesso cruzado entre empresas continua impedido fora do contexto selecionado.",
])
story += [p("20.3 Compatibilidade de endereco", "H2x")]
story += [p("Os caminhos do frontend permanecem relativos ao host atual. O mesmo codigo pode operar em localhost, amagenda.local e no dominio de producao, desde que Apache, HTTPS e VirtualHost estejam configurados corretamente. Nao foi criada dependencia fixa de um dominio no JavaScript.")]

story += [p("21. Agenda e operacao diaria", "H1x")]
story += [p("21.1 Listagem e autorizacao", "H2x")]
story += bullets([
    "A pagina Agenda consulta os agendamentos da empresa ativa na sessao.",
    "Super Admin em suporte recebe os mesmos dados operacionais permitidos ao proprietario da empresa selecionada.",
    "A mensagem 'Acesso a empresa nao autorizado' deixa de ocorrer quando o contexto de suporte e valido.",
])
story += [p("21.2 Cadastro e edicao de agendamento", "H2x")]
story += bullets([
    "Novo agendamento inicia com status Confirmado por padrao.",
    "Edicao, exclusao, pesquisa, horarios disponiveis e validacao de conflito permanecem integrados ao fluxo atual.",
    "A selecao de profissionais considera os profissionais vinculados a empresa.",
    "Botoes de acao dos modais foram alinhados a direita, exceto o modal de Identidade Visual, preservado conforme solicitado.",
])
story += [p("21.3 Cards da Agenda", "H2x")]
story += [p("Os cards exibem horario, data, cliente, servico, duracao, profissional, status, contato por WhatsApp e menu de acoes. O layout se adapta ao conteudo para evitar compressao quando os textos aumentam.")]
story += [PageBreak(), Spacer(1, 12 * mm)]

story += [p("22. Painel Administrativo - Resumo do dia", "H1x")]
story += [p("22.1 Dados reais", "H2x")]
story += bullets([
    "Agendamentos, confirmados, pendentes e cancelados sao calculados com dados reais do banco.",
    "Faturamento do dia informa indisponibilidade enquanto pagamentos nao forem controlados pelo sistema.",
    "Ocupacao semanal utiliza horarios disponiveis e agenda preenchida da semana.",
])
story += [p("22.2 Por profissional", "H2x")]
story += bullets([
    "A secao Por profissional aparece antes de Proximos atendimentos.",
    "Cada profissional possui totais do dia, distribuicao por status e estado atual de atendimento.",
    "O card pode exibir foto de perfil; na ausencia, usa o fallback ja adotado pelo sistema.",
    "O componente cresce conforme as informacoes e reorganiza as colunas de forma responsiva.",
])
story += [p("22.3 Proximos atendimentos", "H2x")]
story += [p("A listagem apresenta ate 10 proximos atendimentos no total, considerando conjuntamente todos os profissionais da empresa e respeitando a ordem cronologica.")]

story += [p("23. Interface global", "H1x")]
story += bullets([
    "Menu lateral foi documentado nas paginas HTML com identificacao por contexto.",
    "Ordem dos itens foi harmonizada entre Agenda, Painel Administrativo e Super Admin.",
    "Cabecalhos superiores da Agenda e do Painel Administrativo foram compactados.",
    "Layout do painel foi ajustado para evitar areas pequenas ou cortadas quando os cards recebem mais dados.",
])
story += [PageBreak(), Spacer(1, 12 * mm)]

story += [p("24. Login web premium", "H1x")]
story += [p("24.1 Escopo", "H2x")]
story += [p("A etapa final de 18/08/2026 altera somente a experiencia desktop. O login por telefone, o login com senha, a sessao, a validacao de empresa, os endpoints, os IDs e os eventos JavaScript permanecem inalterados.")]
story += [p("24.2 Composicao desktop", "H2x")]
spec_data = [
    [p("Propriedade", "CardTitle"), p("Valor final", "CardTitle")],
    [p("Largura", "Bodyx"), p("min(1440px, 92vw)", "Bodyx")],
    [p("Colunas", "Bodyx"), p("60% visual / 40% login", "Bodyx")],
    [p("Bloco interno", "Bodyx"), p("max-width: 420px", "Bodyx")],
    [p("Altura util", "Bodyx"), p("ate 720px, limitada pela viewport", "Bodyx")],
    [p("Breakpoint", "Bodyx"), p("regras exclusivas a partir de 961px", "Bodyx")],
]
spec_table = Table(spec_data, colWidths=[55 * mm, 113 * mm], repeatRows=1)
spec_table.setStyle(TableStyle([
    ("BACKGROUND", (0, 0), (-1, 0), PALE),
    ("GRID", (0, 0), (-1, -1), .6, BORDER),
    ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
    ("LEFTPADDING", (0, 0), (-1, -1), 8),
    ("TOPPADDING", (0, 0), (-1, -1), 6),
    ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
]))
story += [spec_table, Spacer(1, 4 * mm)]
story += [p("24.3 Fallback AmAgenda", "H2x")]
story += bullets([
    "Painel azul usa gradiente, curva inferior e profundidade suave em CSS.",
    "Marca, slogan e beneficios sao textos HTML, mantendo nitidez e adaptacao.",
    "Mascote usa public/imagens/logo.png, comportamento contain e posicionamento na metade inferior, sem ocupar a coluna inteira.",
    "Beneficios discretos: Agenda organizada, Clientes em um so lugar, Atendimentos sob controle e Gestao simplificada.",
])
story += [p("24.4 Empresa personalizada", "H2x")]
story += [p("Quando imagem_login estiver configurada, a imagem ocupa toda a coluna com object-fit: cover e object-position: center. Marca, slogan, beneficios, mascote e efeitos do fallback sao ocultados. nome_exibicao, logo_empresa e o selo Powered by AmAgenda continuam sob responsabilidade do modulo existente de Identidade Visual.")]
story += [p("24.5 Mobile", "H2x")]
story += [p("Nenhuma regra mobile foi modificada nesta etapa desktop. Em 390 x 844, os novos elementos institucionais desktop permanecem ocultos, sem rolagem horizontal.")]
story += [PageBreak(), Spacer(1, 12 * mm)]

story += [p("25. Validacoes e arquivos", "H1x")]
story += [p("25.1 Validacoes executadas", "H2x")]
story += bullets([
    "PHP sem erros de sintaxe em login-cliente.php e login-empresa.php.",
    "JavaScript de Identidade Visual sem erro de sintaxe.",
    "Desktop conferido em 1920 x 1080, 1600 x 900, 1440 x 900 e 1366 x 768.",
    "Em 1366 x 768, card totalmente visivel, sem rolagem horizontal ou vertical da pagina.",
    "Fluxo Continuar com telefone, retorno da etapa, link Acesso restrito e campos E-mail/Senha conferidos.",
    "Console do navegador sem erros durante os testes funcionais.",
])
story += [p("25.2 Principais arquivos atualizados em 18/08/2026", "H2x")]
story += bullets([
    "backend/_auth/login.php; backend/_auth/sessao.php; public/_auth/sessao.js",
    "backend/agenda/*.php; backend/painel_administrativo/resumo_dia/*.php",
    "public/api/api_central.php",
    "public/views/agenda.html; public/views/painel-administrativo/painel-administrativo.html",
    "public/views/login-cliente.php; public/views/login-empresa.php",
    "public/css/agenda/*.css; public/css/painel-administrativo/*.css; public/css/login/*.css",
    "public/js/agenda/*.js; public/js/painel-administrativo/resumo-dia/lista-resumo-dia.js",
])
story += [p("25.3 Observacoes para producao", "H2x")]
story += bullets([
    "Configurar VirtualHost, HTTPS, permissoes e regras do Apache no Ubuntu Server.",
    "Tratar public/uploads como dado persistente e incluir o diretorio no backup.",
    "Validar URLs publicas, envio de SMS e credenciais reais somente no ambiente de homologacao/producao.",
    "Manter testes com Proprietario, Profissional, Recepcao e Super Admin antes da publicacao.",
])
story += [Spacer(1, 4 * mm), p("Fim da atualizacao tecnica de 18/08/2026.", "Smallx")]

doc.build(story)

# Aplica cabecalho e rodape de forma identica em todas as paginas do suplemento.
raw_supplement = PdfReader(str(SUPPLEMENT))
stamped_writer = PdfWriter()
for page_number, page in enumerate(raw_supplement.pages, start=1):
    packet = BytesIO()
    overlay = pdf_canvas.Canvas(packet, pagesize=A4)
    w, h = A4
    overlay.setFillColor(BLUE)
    overlay.rect(0, h - 5 * mm, w, 5 * mm, fill=1, stroke=0)
    overlay.setFont("Helvetica-Bold", 8)
    overlay.setFillColor(NAVY)
    overlay.drawString(18 * mm, h - 14 * mm, "AmAgenda - Memoria tecnica")
    overlay.setFont("Helvetica", 8)
    overlay.setFillColor(MUTED)
    overlay.drawRightString(w - 18 * mm, h - 14 * mm, "Atualizacao tecnica de 18/08/2026")
    overlay.setStrokeColor(BORDER)
    overlay.line(18 * mm, 16 * mm, w - 18 * mm, 16 * mm)
    overlay.setFont("Helvetica", 7.5)
    overlay.drawString(18 * mm, 10.5 * mm, "Suplemento tecnico - ajustes relevantes do dia")
    overlay.drawRightString(w - 18 * mm, 10.5 * mm, f"pagina {page_number}")
    overlay.save()
    packet.seek(0)
    normalized = PageObject.create_blank_page(width=A4[0], height=A4[1])
    vertical_adjustment = -36 if page_number % 2 == 0 else 0
    normalized.merge_transformed_page(page, Transformation().translate(0, vertical_adjustment))
    normalized.merge_page(PdfReader(packet).pages[0])
    stamped_writer.add_page(normalized)
with SUPPLEMENT.open("wb") as stream:
    stamped_writer.write(stream)

source_reader = PdfReader(str(SOURCE))
supplement_reader = PdfReader(str(SUPPLEMENT))
writer = PdfWriter()
for page in source_reader.pages:
    writer.add_page(page)
for page in supplement_reader.pages:
    writer.add_page(page)
writer.add_metadata({
    "/Title": "Memoria Tecnica AmAgenda Atualizada - 18/08/2026",
    "/Author": "AmAgenda",
    "/Subject": "Arquitetura, permissoes, agenda, painel e login web",
})
with OUTPUT.open("wb") as stream:
    writer.write(stream)

print(OUTPUT)
print(f"source_pages={len(source_reader.pages)} supplement_pages={len(supplement_reader.pages)} total_pages={len(writer.pages)}")
