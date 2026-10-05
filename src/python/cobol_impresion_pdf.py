#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
from collections import OrderedDict
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from pathlib import Path

from reportlab.lib.colors import HexColor, black, white
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas

PURPLE = HexColor('#8D34A5')
PURPLE_DARK = HexColor('#6F2385')
LAV = HexColor('#D6B4E2')
LAV2 = HexColor('#EAD8F1')
TEXT = HexColor('#222222')
MUTED = HexColor('#666666')
GRID = HexColor('#D7D7D7')
LIGHT_LINE = HexColor('#BBBBBB')

W, H = A4
LEFT = 12 * mm
RIGHT = W - 12 * mm

EMPRESA = 'GUASTAVINO e IMBERT y CIA SRL'
LEMA = 'su inmobiliaria de confianza'
CASA_CENTRAL = 'Casa Central: SAN MARTIN 2765/71 - TEL. (0342) 4521048 - S3000FSG - SANTA FE'
SUCURSAL = 'Sucursal: AV. 7 DE MARZO 1563 - TEL. (0342) 4744273 - S3016ARE - SANTO TOME'
IVA_EMPRESA = 'I.V.A. RESPONSABLE INSCRIPTO'
CUIT_EMPRESA = '30-52890985-6'
IIBB_EMPRESA = '011-000567-4'
INICIO_ACT = '01/03/1955'


def leer_raw(path: Path) -> str:
    data = path.read_bytes()
    for enc in ('cp1252', 'latin-1'):
        try:
            return data.decode(enc)
        except UnicodeDecodeError:
            pass
    return data.decode('latin-1', errors='replace')


def limpiar_texto(texto: str) -> str:
    texto = texto.replace('\x00', '').replace('\r\n', '\n').replace('\r', '\n')
    return re.sub(r'[\x01-\x08\x0b\x0e-\x1f\x7f]', '', texto)


def monto(s: str) -> Decimal:
    s = re.sub(r'[^0-9,.-]', '', s.strip())
    if not s:
        return Decimal('0')
    if ',' in s:
        s = s.replace('.', '').replace(',', '.')
    try:
        return Decimal(s)
    except InvalidOperation:
        return Decimal('0')


def fmt_monto(v: Decimal) -> str:
    q = v.quantize(Decimal('0.01'))
    entero, dec = f'{q:.2f}'.split('.')
    signo = ''
    if entero.startswith('-'):
        signo, entero = '-', entero[1:]
    grupos = []
    while entero:
        grupos.insert(0, entero[-3:])
        entero = entero[:-3]
    return f"{signo}{'.'.join(grupos)},{dec}"


def txt(c, x, y, s, size=8, font='Helvetica', color=TEXT, align='left'):
    c.setFillColor(color)
    c.setFont(font, size)
    s = '' if s is None else str(s)
    if align == 'right':
        c.drawRightString(x, y, s)
    elif align == 'center':
        c.drawCentredString(x, y, s)
    else:
        c.drawString(x, y, s)


def draw_header_asset(c, header: Path | None, x, y, w):
    """Dibuja la cabecera institucional aprobada como una única imagen 3:1."""
    if header and header.is_file():
        try:
            h = w / 3.0
            c.drawImage(
                ImageReader(str(header)), x, y, width=w, height=h,
                preserveAspectRatio=True, anchor='sw', mask='auto'
            )
            return h
        except Exception:
            pass

    # Fallback únicamente si el recurso gráfico no existe.
    h = w / 3.0
    txt(c, x, y + h*0.47, 'GUASTAVINO e IMBERT', 14, 'Helvetica-Bold', PURPLE)
    txt(c, x + w - 2*mm, y + h*0.36, 'y CIA SRL', 5.5, 'Helvetica-Bold', PURPLE_DARK, 'right')
    txt(c, x + w/2, y + h*0.10, LEMA, 8, 'Helvetica', PURPLE_DARK, 'center')
    return h


def draw_company_block(c, x, y_top, header: Path | None, width: float):
    """Cabecera + datos de sedes en bloque compacto para ganar espacio vertical."""
    h = draw_header_asset(c, header, x - 3*mm, y_top - width/3.0 + 9*mm, width)
    base_y = y_top - h
    # Pegamos las direcciones a la cabecera. IVA queda aproximadamente a la
    # misma altura que INICIO DE ACTIVIDADES del bloque fiscal derecho.
    txt(c, x, base_y + 12*mm, CASA_CENTRAL, 7.4, 'Helvetica', TEXT)
    txt(c, x, base_y + 8*mm, SUCURSAL, 7.4, 'Helvetica', TEXT)
    txt(c, x, base_y + 4*mm, IVA_EMPRESA, 7.4, 'Helvetica', TEXT)
    return base_y + 1.0*mm

def draw_rect_band(c, x, y_top, w, h, fill=LAV):
    c.setFillColor(fill)
    c.rect(x, y_top-h, w, h, fill=1, stroke=0)
    c.setStrokeColor(PURPLE)
    c.setLineWidth(0.8)
    c.line(x, y_top, x+w, y_top)


def normalize_spaces(s: str) -> str:
    return re.sub(r'\s+', ' ', (s or '').strip())


def normalize_upper_key(s: str) -> str:
    return re.sub(r'\s+', '', (s or '').upper())


def is_concepto05_description(detail: str) -> bool:
    key = normalize_upper_key(detail)
    return key.startswith('COM.S/REE.IMP.EXPOSERV+')


def format_receipt_no(receipt_no: str) -> str:
    digits = re.sub(r'\D', '', receipt_no or '')
    if not digits:
        return ''
    if len(digits) <= 6:
        return f'0001-00{digits.zfill(6)}'
    if len(digits) == 8:
        return f'0001-{digits}'
    return digits


def cortar_segunda_copia(linea: str) -> str:
    linea = linea.expandtabs(8)
    if len(linea) < 95:
        return linea.rstrip()
    for i in range(58, min(len(linea)-10, 95)):
        izq = linea[:i].rstrip()
        der = linea[i:].lstrip()
        if len(izq.strip()) < 3 or len(der.strip()) < 3:
            continue
        a = re.sub(r'\s+', ' ', izq.strip())
        b = re.sub(r'\s+', ' ', der.strip())
        a = re.sub(r'\s+p\d+$', '', a, flags=re.I)
        b = re.sub(r'\s+p\d+$', '', b, flags=re.I)
        pref = a[:min(16, len(a))]
        if len(pref) >= 6 and b.startswith(pref):
            return izq
    return linea[:66].rstrip()


@dataclass
class ReceiptItem:
    code: str
    description: str
    amount: Decimal


@dataclass
class ReceiptPage:
    number: int
    items: list[ReceiptItem]
    transport_in: Decimal = Decimal('0')
    transport_out: Decimal = Decimal('0')


@dataclass
class Receipt:
    date: str = ''
    client: str = ''
    account: str = ''
    address: str = ''
    valid_from: str = ''
    valid_to: str = ''
    iva_condition: str = ''
    cuit: str = ''
    receipt_no: str = ''
    amount_words: str = ''
    footer: str = ''
    items: list[ReceiptItem] = None
    pages: list[ReceiptPage] = None
    total: Decimal = Decimal('0')


@dataclass
class DebtRow:
    no: str
    detail: str
    due: str
    nominal: Decimal
    days: str
    interest: Decimal


@dataclass
class DebtLiquidation:
    account: str = ''
    debtor: str = ''
    date: str = ''
    address: str = ''
    interest_type: str = ''
    iva_condition: str = ''
    cuit: str = ''
    valid_from: str = ''
    valid_to: str = ''
    debits: list[DebtRow] = None
    total_debits: Decimal = Decimal('0')
    total_interest: Decimal = Decimal('0')
    total_debits_with_interest: Decimal = Decimal('0')
    credits: list[tuple[str, str, Decimal]] = None
    total_credits: Decimal = Decimal('0')
    liquid_debt: Decimal = Decimal('0')


def parse_receipt(texto: str) -> Receipt:
    """Parsea el recibo COBOL respetando sus saltos de página y transportes.

    El RAW imprime dos copias horizontales idénticas. cortar_segunda_copia()
    conserva una sola, y cada form-feed representa una página lógica COBOL.
    """
    raw_pages = [p for p in texto.split('\f') if p.strip()]
    pages_lines: list[list[str]] = []
    for raw_page in raw_pages:
        page_lines = [cortar_segunda_copia(x) for x in raw_page.split('\n')]
        page_lines = [re.sub(r'\s+p\d+\s*$', '', x, flags=re.I).rstrip() for x in page_lines]
        page_lines = [x for x in page_lines if not re.fullmatch(r'\s*C\d+\s*', x, flags=re.I)]
        pages_lines.append(page_lines)

    all_lines = [line for pg in pages_lines for line in pg]
    r = Receipt(items=[], pages=[])

    # Fecha.
    for line in all_lines:
        m = re.search(r'\b\d{2}/\d{2}/\d{4}\b', line)
        if m:
            r.date = m.group(0)
            break

    # Cuenta, cliente, domicilio y vigencia. En el RAW la fecha desde está en
    # la línea del domicilio y la fecha hasta en la línea siguiente.
    acc_re = re.compile(r'\b\d{4}/\d{5}/\d{2}\b')
    for idx, line in enumerate(all_lines):
        m = acc_re.search(line)
        if not m:
            continue
        r.account = m.group(0)
        r.client = normalize_spaces(line[:m.start()])
        if idx + 1 < len(all_lines):
            l2 = all_lines[idx + 1]
            md = re.search(r'\b\d{2}/\d{2}/\d{4}\b', l2)
            if md:
                r.valid_from = md.group(0)
                r.address = normalize_spaces(l2[:md.start()])
            else:
                r.address = normalize_spaces(l2)
        if idx + 2 < len(all_lines):
            l3 = all_lines[idx + 2]
            fechas = re.findall(r'\b\d{2}/\d{2}/\d{4}\b', l3)
            if fechas:
                r.valid_to = fechas[0]
        break

    # Condición IVA, CUIT y número de recibo. El formato real observado es:
    # Consumidor Final   27.13925544.0   738006   01
    for line in all_lines:
        cui = re.search(r'\b\d{2}[.\-]?\d{8}[.\-]?\d\b', line)
        if not cui:
            continue
        tail = line[cui.end():]
        nr = re.search(r'\b(\d{6})\b', tail)
        if not nr:
            continue
        digits = re.sub(r'\D', '', cui.group(0))
        if len(digits) == 11:
            r.cuit = f'{digits[:2]}-{digits[2:10]}-{digits[10:]}'
        r.receipt_no = nr.group(1)
        iva = normalize_spaces(line[:cui.start()])
        if iva:
            r.iva_condition = iva
        break

    # Texto del importe en letras: aparece en la última página antes del
    # TRANSPORTE de entrada.
    word_lines: list[str] = []
    collecting_words = False
    for line in all_lines:
        sline = normalize_spaces(line)
        if 'PESOS ' in sline.upper():
            collecting_words = True
            word_lines.append(sline)
            continue
        if collecting_words:
            if re.search(r'TRANSPORTE', sline, re.I):
                break
            if sline and not re.search(r'\d', sline):
                word_lines.append(sline)
    r.amount_words = ' '.join(word_lines).strip()

    item_rx = re.compile(r'^\s*(?:(\d{2})\s+)?(.+?)\s+([0-9.]+,[0-9]{2})\s*$')

    for page_no, lines in enumerate(pages_lines, 1):
        page = ReceiptPage(number=page_no, items=[])
        seen_content = False

        for line in lines:
            stripped = line.strip()
            if not stripped:
                continue

            # Transporte entrante: sin dos puntos. Transporte saliente: con ':'
            mt = re.match(r'^\s*TRANSPORTE(:?)\s+([0-9.]+,[0-9]{2})\s*$', line, re.I)
            if mt:
                val = monto(mt.group(2))
                if mt.group(1) == ':':
                    page.transport_out = val
                else:
                    page.transport_in = val
                continue

            if re.search(r'D E B I T O S', line):
                seen_content = True
                continue

            # No interpretar cabecera, referencias internas ni pie como ítems.
            if re.search(r'Sr\.Inquilino|transferencias|depositos bancarios|Consultenos', line, re.I):
                continue
            if 'PESOS ' in line.upper():
                continue

            m = item_rx.match(line)
            if not m:
                continue
            desc = normalize_spaces(m.group(2))
            if not re.search(r'[A-Za-zÁÉÍÓÚÑáéíóúñ]', desc):
                continue
            if desc.upper().startswith('TRANSPORTE'):
                continue
            if acc_re.search(line):
                continue

            # Sólo las líneas de conceptos del cuerpo: código 2 dígitos o la
            # línea de interés sin código.
            code = m.group(1) or ''
            if not code and not desc.upper().startswith('INTERESES CONCEPTO ANTERIOR'):
                continue

            item = ReceiptItem(code, desc, monto(m.group(3)))
            page.items.append(item)
            r.items.append(item)
            seen_content = True

        if page.items or page.transport_in or page.transport_out or seen_content:
            r.pages.append(page)

    r.total = sum((x.amount for x in r.items), Decimal('0'))
    r.footer = ('Sr.Inquilino: Ud puede abonar tambien sus alquileres por '
                'transferencias o depositos bancarios. Consultenos.')
    return r

def parse_debt(texto: str) -> DebtLiquidation:
    d = DebtLiquidation(debits=[], credits=[])
    lines = [x.rstrip() for x in texto.replace('\f', '\n').split('\n')]
    for line in lines:
        m = re.search(r'CTA\.NRO\.\.:\s*([0-9/]+)', line)
        if m:
            d.account = m.group(1)
        m = re.search(r'DEUDOR\s*\.\.:\s*(.*?)\s{2,}FECHA:\s*(\d{2}/\d{2}/\d{4})', line)
        if m:
            d.debtor = normalize_spaces(m.group(1))
            d.date = m.group(2)
        m = re.search(r'DOMICILIO:\s*(.*?)\s{2,}INTERES:\s*(.*)$', line)
        if m:
            d.address = normalize_spaces(m.group(1))
            d.interest_type = normalize_spaces(m.group(2))

    debit_start = next((i for i, x in enumerate(lines) if 'D E B I T O S' in x), None)
    total_deb_idx = next((i for i, x in enumerate(lines) if 'TOTAL DEBITOS' in x), None)
    if debit_start is not None and total_deb_idx is not None:
        # Hay dos formatos válidos en el listado COBOL:
        # 1) detalle + vencimiento + nominal + días + interés
        # 2) detalle + nominal (movimientos sin vencimiento/interés)
        rx = re.compile(r'^\s*(\d{3})\s+(.+?)\s+(\d{2}/\d{2}/\d{4})\s+([0-9.]+,[0-9]{2})\s+(\d+)\s+([0-9.]+,[0-9]{2})\s*$')
        rx_sin_interes = re.compile(r'^\s*(\d{3})\s+(.+?)\s+([0-9.]+,[0-9]{2})\s*$')
        raw_rows: list[DebtRow] = []
        for line in lines[debit_start + 1:total_deb_idx]:
            m = rx.match(line)
            if m:
                raw_rows.append(DebtRow(m.group(1), normalize_spaces(m.group(2)), m.group(3), monto(m.group(4)), m.group(5), monto(m.group(6))))
                continue
            m = rx_sin_interes.match(line)
            if m:
                raw_rows.append(DebtRow(m.group(1), normalize_spaces(m.group(2)), '', monto(m.group(3)), '', Decimal('0')))

        grouped: list[DebtRow] = []
        concepto05_by_due: OrderedDict[str, int] = OrderedDict()
        for row in raw_rows:
            if is_concepto05_description(row.detail):
                idx = concepto05_by_due.get(row.due)
                if idx is None:
                    concepto05_by_due[row.due] = len(grouped)
                    grouped.append(DebtRow(row.no, 'COM.S/REE.IMP.EXPoSERV +', row.due, row.nominal, row.days, row.interest))
                else:
                    grouped[idx].nominal += row.nominal
                    grouped[idx].interest += row.interest
                    try:
                        grouped[idx].days = str(max(int(grouped[idx].days or '0'), int(row.days or '0')))
                    except ValueError:
                        pass
            else:
                grouped.append(row)

        for i, row in enumerate(grouped, 1):
            row.no = f'{i:03d}'
        d.debits = grouped

        nums = re.findall(r'([0-9.]+,[0-9]{2})', lines[total_deb_idx])
        if nums:
            d.total_debits = monto(nums[0])
            if len(nums) > 1:
                d.total_interest = monto(nums[1])
        for line in lines[total_deb_idx + 1:total_deb_idx + 4]:
            vals = re.findall(r'([0-9.]+,[0-9]{2})', line)
            if len(vals) == 1:
                d.total_debits_with_interest = monto(vals[0])
                break

    credit_start = next((i for i, x in enumerate(lines) if 'C R E D I T O S' in x), None)
    total_cred_idx = next((i for i, x in enumerate(lines) if 'TOTAL CREDITOS' in x), None)
    if credit_start is not None and total_cred_idx is not None:
        rx2 = re.compile(r'^\s*(\d{3})\s+(.+?)\s+([0-9.]+,[0-9]{2})\s*$')
        for line in lines[credit_start + 1:total_cred_idx]:
            m = rx2.match(line)
            if m:
                d.credits.append((m.group(1), normalize_spaces(m.group(2)), monto(m.group(3))))
        vals = re.findall(r'([0-9.]+,[0-9]{2})', lines[total_cred_idx])
        if vals:
            d.total_credits = monto(vals[0])

    for line in lines:
        if 'DEUDA LIQUIDA AL' in line:
            vals = re.findall(r'([0-9.]+,[0-9]{2})', line)
            if vals:
                d.liquid_debt = monto(vals[-1])
    return d


def draw_table_header(c, x, y, widths: list[float], titles: list[tuple[str, str]], h=7.5*mm):
    c.setFillColor(LAV)
    c.rect(x, y-h, sum(widths), h, fill=1, stroke=0)
    c.setFillColor(PURPLE)
    c.rect(x, y-1.5*mm, sum(widths), 1.5*mm, fill=1, stroke=0)
    xx = x
    for w, (title, align) in zip(widths, titles):
        xpos = xx + (w/2 if align == 'center' else (w - 2*mm if align == 'right' else 1.8*mm))
        txt(c, xpos, y-5.6*mm, title, 6.8, 'Helvetica-Bold', TEXT, 'center' if align == 'center' else align)
        xx += w


def draw_receipt_header(c, r: Receipt, y_top: float, logo: Path | None, label: str):
    """Cabecera idéntica para ORIGINAL y DUPLICADO; sólo cambia la etiqueta."""
    block_y = draw_company_block(c, LEFT, y_top, logo, 91*mm)

    box_x = LEFT + 94*mm
    box_size = 13.5*mm
    c.setFillColor(black)
    c.rect(box_x, y_top-12.5*mm, box_size, box_size, fill=1, stroke=0)
    txt(c, box_x + box_size/2, y_top-8.5*mm, 'X', 20, 'Helvetica-Bold', white, 'center')

    right_x = box_x + box_size + 8*mm
    txt(c, RIGHT, y_top-1*mm, label, 8.3, 'Helvetica-Bold', PURPLE, 'right')
    txt(c, right_x, y_top-4*mm, 'DOCUMENTO NO VALIDO COMO FACTURA', 7.2, 'Helvetica-Bold', TEXT)
    txt(c, right_x, y_top-8.5*mm, f'RECIBO N°: {format_receipt_no(r.receipt_no)}', 12, 'Helvetica-Bold', TEXT)
    txt(c, right_x, y_top-12.5*mm, f'Fecha: {r.date}', 8.3, 'Helvetica-Bold', TEXT)
    txt(c, right_x, y_top-18*mm, f'CUIT N°: {CUIT_EMPRESA}', 7.4, 'Helvetica', TEXT)
    txt(c, right_x, y_top-22*mm, f'INGR. BRUTOS N°: {IIBB_EMPRESA}', 7.4, 'Helvetica', TEXT)
    txt(c, right_x, y_top-26*mm, f'INICIO DE ACTIVIDADES: {INICIO_ACT}', 7.4, 'Helvetica', TEXT)

    line_y = min(block_y, y_top - 28*mm) - 0.8*mm
    c.setStrokeColor(PURPLE)
    c.setLineWidth(1.2)
    c.line(LEFT, line_y, RIGHT, line_y)
    return line_y - 1.2*mm


def draw_receipt_band(c, r: Receipt, y_top: float):
    """Mismos datos para ORIGINAL y DUPLICADO."""
    h = 16.5*mm
    draw_rect_band(c, LEFT, y_top, RIGHT-LEFT, h, LAV)
    y = y_top - 5.4*mm
    step = 3.8*mm
    left_label_x = LEFT + 3*mm
    left_value_x = LEFT + 27*mm
    right_label_x = LEFT + 95*mm
    right_value_x = LEFT + 120*mm
    vigencia = f'{r.valid_from} al {r.valid_to}'.strip(' al')
    rows = [
        ('Inquilino:', r.client, 'Cuenta:', r.account),
        ('Domicilio:', r.address, 'Vigencia:', vigencia),
        ('Condición IVA:', r.iva_condition, 'CUIT:', r.cuit),
    ]
    for a, av, b, bv in rows:
        txt(c, left_label_x, y, a, 7.2, 'Helvetica-Bold')
        txt(c, left_value_x, y, av, 8.3, 'Helvetica-Bold')
        txt(c, right_label_x, y, b, 7.2, 'Helvetica-Bold')
        txt(c, right_value_x, y, bv, 8.3, 'Helvetica-Bold')
        y -= step
    return y_top - h


def draw_receipt_page_body(c, r: Receipt, page: ReceiptPage, y_start: float, half_bottom: float):
    """Dibuja una página lógica del recibo dentro de media A4."""
    y = y_start - 3.3*mm
    widths = [14*mm, 139*mm, 33*mm]
    draw_table_header(c, LEFT, y, widths, [('Código', 'center'), ('Concepto', 'left'), ('Importe', 'right')], h=6.8*mm)
    row_y = y - 9.0*mm
    line_h = 3.7*mm

    if page.transport_in:
        txt(c, LEFT + 16.5*mm, row_y, 'TRANSPORTE', 6.8, 'Helvetica-Bold', MUTED)
        txt(c, RIGHT - 2*mm, row_y, fmt_monto(page.transport_in), 6.8, 'Helvetica-Bold', MUTED, 'right')
        row_y -= line_h

    for item in page.items:
        txt(c, LEFT + 7*mm, row_y, item.code, 6.6, align='center')
        txt(c, LEFT + 16.5*mm, row_y, item.description[:70], 6.8)
        txt(c, RIGHT - 2*mm, row_y, fmt_monto(item.amount), 6.8, align='right')
        row_y -= line_h

    if page.transport_out:
        # El transporte saliente se alinea hacia el pie de cada mitad, sin
        # alterar el acumulado que trae el RAW.
        transport_y = max(half_bottom + 10.5*mm, row_y - 1.5*mm)
        c.setStrokeColor(GRID)
        c.line(LEFT, transport_y + 2.8*mm, RIGHT, transport_y + 2.8*mm)
        txt(c, LEFT + 2*mm, transport_y, 'TRANSPORTE:', 7.0, 'Helvetica-Bold', MUTED)
        txt(c, RIGHT - 2*mm, transport_y, fmt_monto(page.transport_out), 7.0, 'Helvetica-Bold', MUTED, 'right')
        footer_y = half_bottom + 6.0*mm
        txt(c, LEFT, footer_y, r.footer, 5.2, 'Helvetica', MUTED)
        return

    # Última página: texto en letras y total definitivo.
    words = normalize_spaces(r.amount_words)
    if words:
        txt(c, LEFT, max(half_bottom + 15*mm, row_y - 1.5*mm), ('Son: ' + words)[:180], 5.8, 'Helvetica-Bold')

    total_y = half_bottom + 9.5*mm
    panel_w = 46*mm
    c.setFillColor(LAV2)
    c.rect(RIGHT - panel_w, total_y - 5.5*mm, panel_w, 8*mm, fill=1, stroke=0)
    txt(c, RIGHT - panel_w + 4*mm, total_y - 2.5*mm, 'TOTAL', 8.0, 'Helvetica-Bold')
    txt(c, RIGHT - 3*mm, total_y - 2.5*mm, '$ ' + fmt_monto(r.total), 10.0, 'Helvetica-Bold', align='right')
    txt(c, LEFT, half_bottom + 5.0*mm, r.footer, 5.2, 'Helvetica', MUTED)


def draw_receipt_half_page(c, r: Receipt, page: ReceiptPage, y_top: float, half_bottom: float, label: str, logo: Path | None):
    y = draw_receipt_header(c, r, y_top, logo, label)
    y = draw_receipt_band(c, r, y)
    draw_receipt_page_body(c, r, page, y, half_bottom)


def generar_recibo(texto: str, salida: Path, logo: Path | None):
    r = parse_receipt(texto)
    c = canvas.Canvas(str(salida), pagesize=A4)
    c.setTitle(f'Recibo de liquidación {r.receipt_no}'.strip())

    pages = r.pages or [ReceiptPage(number=1, items=r.items or [])]
    cut = H / 2

    for page in pages:
        # ORIGINAL arriba y DUPLICADO abajo son copias exactas. Sólo cambia
        # la palabra ORIGINAL/DUPLICADO.
        draw_receipt_half_page(c, r, page, H - 11*mm, cut, 'ORIGINAL', logo)

        c.saveState()
        c.setStrokeColor(HexColor('#999999'))
        c.setDash(3, 3)
        c.line(10*mm, cut, W-10*mm, cut)
        c.restoreState()
        txt(c, W/2, cut + 1.7*mm, 'corte', 5.3, 'Helvetica', MUTED, 'center')

        draw_receipt_half_page(c, r, page, cut - 11*mm, 0, 'DUPLICADO', logo)
        c.showPage()

    c.save()

def draw_liquidacion_header(c, d: DebtLiquidation, y_top: float, logo: Path | None):
    left_area_w = 106 * mm
    bottom_y = draw_company_block(c, LEFT, y_top, logo, left_area_w)

    title_x = LEFT + left_area_w + 5*mm
    txt(c, RIGHT, y_top - 8*mm, 'LIQUIDACIÓN DE DEUDA', 15, 'Helvetica-Bold', TEXT, 'right')
    txt(c, RIGHT, y_top - 15*mm, f'Fecha: {d.date}', 11, 'Helvetica-Bold', TEXT, 'right')
    txt(c, title_x + 11*mm, y_top - 23.4*mm, f'CUIT N°: {CUIT_EMPRESA}', 7.4, 'Helvetica', TEXT)
    txt(c, title_x + 11*mm, y_top - 27.4*mm, f'INGR. BRUTOS N°: {IIBB_EMPRESA}', 7.4, 'Helvetica', TEXT)
    txt(c, title_x + 11*mm, y_top - 31.4*mm, f'INICIO DE ACTIVIDADES: {INICIO_ACT}', 7.4, 'Helvetica', TEXT)

    line_y = min(bottom_y, y_top - 1.5*mm) - 0.8*mm
    c.setStrokeColor(PURPLE)
    c.setLineWidth(1.3)
    c.line(LEFT, line_y, RIGHT, line_y)
    return line_y - 1.5*mm


def draw_liquidacion_band(c, d: DebtLiquidation, y_top: float):
    h = 16.5 * mm
    draw_rect_band(c, LEFT, y_top, RIGHT-LEFT, h, LAV)
    y = y_top - 6.2*mm
    step = 3.8 * mm
    left_label_x = LEFT + 4*mm
    left_value_x = LEFT + 29*mm
    right_label_x = LEFT + 104*mm
    right_value_x = LEFT + 128*mm
    vigencia = f'{d.valid_from} al {d.valid_to}'.strip(' al')
    rows = [
        ('Inquilino:', d.debtor, 'Cuenta:', d.account),
        ('Domicilio:', d.address, 'Vigencia:', vigencia),
        ('Condición IVA:', d.iva_condition, 'CUIT:', d.cuit),
    ]
    for a, av, b, bv in rows:
        txt(c, left_label_x, y, a, 8.0, 'Helvetica-Bold')
        txt(c, left_value_x, y, av, 9.0, 'Helvetica-Bold')
        txt(c, right_label_x, y, b, 8.0, 'Helvetica-Bold')
        txt(c, right_value_x, y, bv, 9.0, 'Helvetica-Bold')
        y -= step
    return y_top - h


def draw_liquidacion_continuacion(c, d: DebtLiquidation, widths: list[float], transporte_nominal: Decimal, transporte_interes: Decimal):
    """Cabecera compacta para páginas 2+ y transporte de acumulados."""
    txt(c, LEFT, H - 14*mm, 'LIQUIDACIÓN DE DEUDA - CONTINUACIÓN', 12, 'Helvetica-Bold', TEXT)
    txt(c, RIGHT, H - 14*mm, f'Fecha: {d.date}', 8.2, 'Helvetica-Bold', TEXT, 'right')
    txt(c, LEFT, H - 20*mm, d.debtor, 8.2, 'Helvetica-Bold', TEXT)
    txt(c, RIGHT, H - 20*mm, f'Cuenta: {d.account}', 8.2, 'Helvetica-Bold', TEXT, 'right')

    y = H - 26*mm
    draw_table_header(c, LEFT, y, widths, [
        ('N°', 'center'),
        ('Detalle', 'left'),
        ('Vencim.', 'center'),
        ('Val. nom.', 'right'),
        ('Días', 'center'),
        ('Intereses', 'right'),
    ], h=8*mm)

    row_y = y - 11.5*mm
    c.setStrokeColor(GRID)
    c.line(LEFT, row_y + 2.0*mm, RIGHT, row_y + 2.0*mm)
    txt(c, LEFT + 2*mm, row_y - 1.8*mm, 'TRANSPORTE ANTERIOR', 7.2, 'Helvetica-Bold', MUTED)
    txt(c, LEFT + sum(widths[:-2]) - 1.5*mm, row_y - 1.8*mm,
        fmt_monto(transporte_nominal), 7.2, 'Helvetica-Bold', MUTED, 'right')
    txt(c, RIGHT - 1.5*mm, row_y - 1.8*mm,
        fmt_monto(transporte_interes), 7.2, 'Helvetica-Bold', MUTED, 'right')
    return row_y - 7.2*mm


def draw_transporte_pie(c, widths: list[float], nominal: Decimal, interes: Decimal):
    """Acumulado al pie antes de continuar en otra hoja."""
    y = 14.5 * mm
    c.setStrokeColor(GRID)
    c.line(LEFT, y + 4.0*mm, RIGHT, y + 4.0*mm)
    txt(c, LEFT + 2*mm, y, 'TRANSPORTE', 7.2, 'Helvetica-Bold', MUTED)
    txt(c, LEFT + sum(widths[:-2]) - 1.5*mm, y,
        fmt_monto(nominal), 7.2, 'Helvetica-Bold', MUTED, 'right')
    txt(c, RIGHT - 1.5*mm, y,
        fmt_monto(interes), 7.2, 'Helvetica-Bold', MUTED, 'right')


def draw_liquidacion_final(c, d: DebtLiquidation, row_y: float, widths: list[float]):
    """Totales/créditos/deuda. Se ubican bajos para aprovechar la hoja."""
    line_h = 4.9 * mm

    # Alto necesario del bloque final. Si hay créditos se reserva su tabla.
    credit_h = (10*mm + len(d.credits)*line_h + 10*mm) if d.credits else 0
    final_h = 9*mm + credit_h + 18*mm

    # Si los renglones llegaron demasiado abajo, los totales pasan completos
    # a otra hoja en lugar de pisarse o desaparecer.
    if row_y < final_h + 15*mm:
        c.showPage()
        txt(c, LEFT, H - 14*mm, 'LIQUIDACIÓN DE DEUDA - TOTALES', 12, 'Helvetica-Bold', TEXT)
        txt(c, RIGHT, H - 14*mm, f'Fecha: {d.date}', 8.2, 'Helvetica-Bold', TEXT, 'right')
        txt(c, LEFT, H - 20*mm, d.debtor, 8.2, 'Helvetica-Bold', TEXT)
        txt(c, RIGHT, H - 20*mm, f'Cuenta: {d.account}', 8.2, 'Helvetica-Bold', TEXT, 'right')
        row_y = H - 32*mm

    # Si sobra espacio, bajamos el bloque final. Esto deja entrar más líneas
    # de detalle antes de decidir un salto de página.
    target_y = 58*mm + credit_h
    row_y = min(row_y, target_y)

    c.setStrokeColor(GRID)
    c.line(LEFT, row_y + 2*mm, RIGHT, row_y + 2*mm)
    txt(c, LEFT + 2*mm, row_y - 2*mm, 'TOTAL DÉBITOS', 7.7, 'Helvetica-Bold')
    txt(c, LEFT + sum(widths[:-2]) - 1.5*mm, row_y - 2*mm,
        fmt_monto(d.total_debits), 7.7, 'Helvetica-Bold', align='right')
    txt(c, RIGHT - 1.5*mm, row_y - 2*mm,
        fmt_monto(d.total_interest), 7.7, 'Helvetica-Bold', align='right')
    row_y -= 9*mm

    if d.credits:
        widths2 = [14*mm, 138*mm, 33*mm]
        draw_table_header(c, LEFT, row_y, widths2,
                          [('N°', 'center'), ('Detalle', 'left'), ('Importe', 'right')])
        row_y -= 10*mm
        for no, desc, val in d.credits:
            txt(c, LEFT + widths2[0]/2, row_y, no, 6.8, align='center')
            txt(c, LEFT + widths2[0] + 1*mm, row_y, desc[:72], 6.8)
            txt(c, RIGHT - 1.5*mm, row_y, fmt_monto(val), 6.8, align='right')
            row_y -= line_h
        c.setStrokeColor(GRID)
        c.line(LEFT, row_y + 2*mm, RIGHT, row_y + 2*mm)
        txt(c, LEFT + 2*mm, row_y - 2*mm, 'TOTAL CRÉDITOS', 7.7, 'Helvetica-Bold')
        txt(c, RIGHT - 1.5*mm, row_y - 2*mm,
            fmt_monto(d.total_credits), 7.7, 'Helvetica-Bold', align='right')
        row_y -= 10*mm

    c.setFillColor(LAV2)
    c.rect(LEFT, row_y - 12*mm, RIGHT-LEFT, 14*mm, fill=1, stroke=0)
    txt(c, LEFT + 4*mm, row_y - 6.2*mm,
        f'DEUDA LÍQUIDA AL {d.date}', 10, 'Helvetica-Bold', PURPLE_DARK)
    txt(c, RIGHT - 4*mm, row_y - 7.3*mm,
        '$ ' + fmt_monto(d.liquid_debt), 16, 'Helvetica-Bold', TEXT, 'right')


def draw_liquidacion_table(c, d: DebtLiquidation, y_start: float):
    txt(c, LEFT, y_start - 4*mm, 'DÉBITOS', 9, 'Helvetica-Bold', PURPLE_DARK)

    y = y_start - 6*mm
    widths = [14*mm, 81*mm, 25*mm, 24*mm, 14*mm, 28*mm]
    draw_table_header(c, LEFT, y, widths, [
        ('N°', 'center'),
        ('Detalle', 'left'),
        ('Vencim.', 'center'),
        ('Val. nom.', 'right'),
        ('Días', 'center'),
        ('Intereses', 'right'),
    ], h=8*mm)

    row_y = y - 12*mm
    line_h = 4.9*mm
    # El detalle puede bajar casi hasta el pie. Sólo reservamos espacio para
    # el TRANSPORTE cuando efectivamente hay otra hoja.
    bottom_rows = 26*mm
    acum_nominal = Decimal('0')
    acum_interes = Decimal('0')

    for idx, row in enumerate(d.debits):
        # Si este renglón no entra y todavía quedan movimientos, cerramos la
        # hoja con transporte y continuamos los acumulados en la siguiente.
        if row_y < bottom_rows:
            draw_transporte_pie(c, widths, acum_nominal, acum_interes)
            c.showPage()
            row_y = draw_liquidacion_continuacion(
                c, d, widths, acum_nominal, acum_interes
            )

        xx = LEFT
        txt(c, xx + widths[0]/2, row_y, row.no, 6.8, align='center'); xx += widths[0]
        txt(c, xx + 1*mm, row_y, row.detail[:42], 6.8); xx += widths[1]
        txt(c, xx + widths[2]/2, row_y, row.due, 6.8, align='center'); xx += widths[2]
        txt(c, xx + widths[3] - 1.5*mm, row_y, fmt_monto(row.nominal), 6.8, align='right'); xx += widths[3]
        txt(c, xx + widths[4]/2, row_y, row.days, 6.8, align='center'); xx += widths[4]
        txt(c, xx + widths[5] - 1.5*mm, row_y, fmt_monto(row.interest), 6.8, align='right')

        acum_nominal += row.nominal
        acum_interes += row.interest
        row_y -= line_h

    draw_liquidacion_final(c, d, row_y, widths)


def generar_liquidacion(texto: str, salida: Path, logo: Path | None):
    d = parse_debt(texto)
    c = canvas.Canvas(str(salida), pagesize=A4)
    c.setTitle('Liquidación de deuda')
    y = draw_liquidacion_header(c, d, H - 8*mm, logo)
    y = draw_liquidacion_band(c, d, y)
    draw_liquidacion_table(c, d, y)
    c.save()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--raw', required=True)
    ap.add_argument('--tipo', required=True, choices=['LIQUIDACION_DEUDA', 'RECIBO_LIQUIDACION'])
    ap.add_argument('--salida', required=True)
    ap.add_argument('--logo')
    args = ap.parse_args()
    raw = Path(args.raw)
    out = Path(args.salida)
    out.parent.mkdir(parents=True, exist_ok=True)
    logo = Path(args.logo) if args.logo else None
    texto = limpiar_texto(leer_raw(raw))
    if args.tipo == 'RECIBO_LIQUIDACION':
        generar_recibo(texto, out, logo)
    else:
        generar_liquidacion(texto, out, logo)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
