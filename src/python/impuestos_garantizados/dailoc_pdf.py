from __future__ import annotations

from decimal import Decimal
from pathlib import Path

from reportlab.lib.colors import HexColor, black, white
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.pdfgen import canvas

from dailoc_parser import DailocDetalle, DailocItem


def dinero(v: Decimal) -> str:
    s = f'{v:,.2f}'.replace(',', 'X').replace('.', ',').replace('X', '.')
    return s


def porcentaje(v: Decimal) -> str:
    if not v:
        return ''
    return f'{v:.2f}'.replace('.', ',') + '%'


def formatear_periodo_cabecera(valor: str) -> str:
    p = (valor or '').replace(' de ', '/').strip()
    partes = p.split('/')
    return f'{partes[0].capitalize()}/{partes[1]}' if len(partes) == 2 else valor


def _elidir(c, texto: str, fuente: str, tam: float, ancho: float) -> str:
    text = str(texto or '')
    if stringWidth(text, fuente, tam) <= ancho:
        return text
    suf = '…'
    while text and stringWidth(text + suf, fuente, tam) > ancho:
        text = text[:-1]
    return text + suf


def generar_pdf_dailoc(det: DailocDetalle, destino: Path, cfg: dict) -> None:
    W, H = A4
    c = canvas.Canvas(str(destino), pagesize=A4)
    c.setTitle(destino.name)
    c.setAuthor('Guastavino e Imbert')
    violeta = HexColor('#8f159c')
    violeta_claro = HexColor('#d9b5e9')
    fondo_importe = HexColor('#ead8f4')
    logo_path = Path(__file__).with_name('GeI_fox.png')

    # Formato Python: la paginación depende del espacio real, no de las 23 líneas COBOL.
    max_filas = 43
    paginas = [det.items[i:i + max_filas] for i in range(0, len(det.items), max_filas)] or [[]]
    total_paginas = len(paginas)

    x_dir, w_dir = 5 * mm, 52 * mm
    x_tipo, w_tipo = 58 * mm, 24 * mm
    x_per, w_per = 83 * mm, 20 * mm
    x_part, w_part = 104 * mm, 34 * mm
    x_imp, w_imp = 139 * mm, 25 * mm
    x_pct, w_pct = 165 * mm, 16 * mm
    x_prop, w_prop = 182 * mm, 23 * mm
    top_tabla = 207 * mm
    row_h = 3.35 * mm

    def txt(x, y, text, size=7.2, bold=False, align='left', maxw=None):
        font = 'Helvetica-Bold' if bold else 'Helvetica'
        c.setFont(font, size)
        c.setFillColor(black)
        value = str(text or '')
        if maxw:
            value = _elidir(c, value, font, size, maxw)
        if align == 'right':
            c.drawRightString(x, y, value)
        elif align == 'center':
            c.drawCentredString(x, y, value)
        else:
            c.drawString(x, y, value)

    def cabecera(nro: int):
        if logo_path.exists():
            c.drawImage(ImageReader(str(logo_path)), 5 * mm, H - 35 * mm,
                        width=196 * mm, height=28 * mm, preserveAspectRatio=True,
                        anchor='sw', mask='auto')

        txt(205 * mm, H - 13 * mm, 'DETALLE DE LOS IMPUESTOS GARANTIZADOS', 10, True, 'right')
        c.setStrokeColor(violeta)
        c.setLineWidth(1.2)
        c.line(5 * mm, H - 39 * mm, 205 * mm, H - 39 * mm)

        c.setFillColor(violeta_claro)
        c.rect(5 * mm, H - 69 * mm, 200 * mm, 27 * mm, stroke=0, fill=1)
        txt(7 * mm, H - 49 * mm, 'Propietario:', 7, True)
        txt(31 * mm, H - 49 * mm, det.propietario.title(), 8, True, maxw=102 * mm)
        txt(139 * mm, H - 49 * mm, 'Cuenta:', 7, True)
        txt(158 * mm, H - 49 * mm, det.cuenta, 8, True)
        txt(7 * mm, H - 57 * mm, 'Período:', 7, True)
        txt(31 * mm, H - 57 * mm, formatear_periodo_cabecera(det.periodo), 8, True)
        txt(85 * mm, H - 57 * mm, 'Detalle N°:', 7, True)
        txt(109 * mm, H - 57 * mm, str(det.numero), 8, True)
        txt(166 * mm, H - 57 * mm, 'Hoja:', 7, True)
        txt(204 * mm, H - 57 * mm, f'{nro} / {total_paginas}', 8, True, 'right')

        headers = [
            (x_dir, w_dir, 'Inmueble'), (x_tipo, w_tipo, 'Tipo'),
            (x_per, w_per, 'Período'), (x_part, w_part, 'Partida / Padrón / Cta.'),
            (x_imp, w_imp, 'Importe'), (x_pct, w_pct, '% Prop.'),
            (x_prop, w_prop, 'A cargo prop.'),
        ]
        for x, w, titulo in headers:
            c.setFillColor(violeta)
            c.rect(x, top_tabla + 8 * mm, w, 1.4 * mm, stroke=0, fill=1)
            c.setFillColor(violeta_claro)
            c.rect(x, top_tabla + 1 * mm, w, 7 * mm, stroke=0, fill=1)
            txt(x + w / 2, top_tabla + 3.2 * mm, titulo, 6.5, True, 'center', w - 1 * mm)
        c.setStrokeColor(violeta)
        c.line(5 * mm, top_tabla, 205 * mm, top_tabla)

    for pnum, items in enumerate(paginas, 1):
        cabecera(pnum)
        y = top_tabla - 5 * mm
        for item in items:
            txt(x_dir + 1 * mm, y, item.inmueble.title(), 6.4, maxw=w_dir - 2 * mm)
            txt(x_tipo + 1 * mm, y, item.tipo, 6.4, maxw=w_tipo - 2 * mm)
            txt(x_per + w_per - 1 * mm, y, item.periodo, 6.4, align='right')
            txt(x_part + w_part - 1 * mm, y, item.partida, 6.4, align='right', maxw=w_part - 2 * mm)
            for x, w in ((x_imp, w_imp), (x_prop, w_prop)):
                c.setFillColor(fondo_importe)
                c.rect(x, y - 1.5 * mm, w, row_h + 1.3 * mm, stroke=0, fill=1)
            txt(x_imp + w_imp - 1 * mm, y, dinero(item.importe), 6.4, align='right')
            txt(x_pct + w_pct - 1 * mm, y, porcentaje(item.porcentaje_propietario), 6.4, align='right')
            if item.importe_propietario:
                txt(x_prop + w_prop - 1 * mm, y, dinero(item.importe_propietario), 6.4, align='right')
            y -= row_h

        if pnum == total_paginas:
            fy = 39 * mm
            x_band = 104 * mm
            band_w = 101 * mm
            c.setFillColor(violeta_claro)
            c.rect(x_band, fy - 3 * mm, band_w, 25 * mm, stroke=0, fill=1)
            txt(x_band + 2 * mm, fy + 15 * mm, 'Total impuestos', 7.5, True)
            txt(203 * mm, fy + 15 * mm, '$ ' + dinero(det.total_impuestos), 7.5, True, 'right')
            txt(x_band + 2 * mm, fy + 8 * mm, 'Comisión + IVA (si corresponde)', 7.5, True)
            txt(203 * mm, fy + 8 * mm, '$ ' + dinero(det.total_comision_iva_cobol), 7.5, True, 'right')
            txt(x_band + 2 * mm, fy + 1 * mm, 'Total a cargo del propietario', 8.5, True)
            txt(203 * mm, fy + 1 * mm, '$ ' + dinero(det.total_propietario), 8.5, True, 'right')

            c.setStrokeColor(violeta)
            c.line(5 * mm, 5 * mm, 205 * mm, 5 * mm)
        c.showPage()

    c.save()
