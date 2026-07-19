from __future__ import annotations

import re
from dataclasses import asdict, dataclass, field
from decimal import Decimal
from pathlib import Path

from reportlab.lib.colors import HexColor, black, white
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.pdfgen import canvas


@dataclass
class Item:
    nombre: str = ""
    detalle: str = ""
    vencimiento: str = ""
    debe: Decimal = Decimal("0")
    haber: Decimal = Decimal("0")
    referencia: str = ""
    numero_movimiento_origen: str = ""
    fecha_movimiento_origen: str = ""
    archivo_origen: str = ""
    orden_origen: int = 0
    tipo_movimiento: str = ""

    def dict(self) -> dict:
        data = asdict(self)
        data["debe"] = str(self.debe)
        data["haber"] = str(self.haber)
        return data


@dataclass
class Liquidacion:
    origen: str
    sede: str
    tipo: str
    fecha: str = ""
    periodo: str = ""
    propietario: str = ""
    domicilio: str = ""
    cp: str = ""
    localidad: str = ""
    provincia: str = ""
    condicion_iva: str = ""
    cuit: str = ""
    cuenta: str = ""
    comprobante: str = ""
    codigo_aux: str = ""
    total: Decimal = Decimal("0")
    total_bruto: Decimal = Decimal("0")
    banco: str = ""
    tipo_cuenta_banco: str = ""
    copropietario: str = ""
    porcentaje: str = ""
    total_copropietario: Decimal = Decimal("0")
    total_debe: Decimal = Decimal("0")
    total_haber: Decimal = Decimal("0")
    total_neto_gravado: Decimal = Decimal("0")
    total_iva: Decimal = Decimal("0")
    total_final: Decimal = Decimal("0")
    items: list[Item] = field(default_factory=list)
    raw: list[str] = field(default_factory=list)
    numero_interno: int | None = None

    def dict(self) -> dict:
        data = asdict(self)
        for key in (
            "total",
            "total_bruto",
            "total_copropietario",
            "total_debe",
            "total_haber",
            "total_neto_gravado",
            "total_iva",
            "total_final",
        ):
            data[key] = str(getattr(self, key))
        data["items"] = [item.dict() for item in self.items]
        return data


def dinero(valor: Decimal) -> str:
    texto = f"{abs(valor):,.2f}"
    texto = texto.replace(",", "X").replace(".", ",").replace("X", ".")
    return texto + (" DB" if valor < 0 else "")


def dinero_con_signo(valor: Decimal) -> str:
    texto = f"{abs(valor):,.2f}"
    texto = texto.replace(",", "X").replace(".", ",").replace("X", ".")
    return ("-" if valor < 0 else "") + texto


def formatear_cuit(cuit: str) -> str:
    digitos = re.sub(r"\D", "", cuit or "")
    return f"{digitos[:2]}-{digitos[2:10]}-{digitos[10:]}" if len(digitos) == 11 else cuit


def formatear_periodo(valor: str) -> str:
    match = re.match(r"^\s*([A-ZÁÉÍÓÚÑ]+)\s+(\d{4})\s*$", valor or "", re.I)
    if not match:
        return valor or ""
    return f"{match.group(1).capitalize()}/{match.group(2)}"


def generar_pdf(liq: Liquidacion, dest: Path, cfg: dict) -> None:
    """Copia piloto aislada del layout ReportLab existente.

    Este modulo vive dentro de gei-web y no participa del generador productivo.
    """

    width, height = A4
    dest.parent.mkdir(parents=True, exist_ok=True)
    pdf = canvas.Canvas(str(dest), pagesize=A4)
    violeta = HexColor("#8f159c")
    violeta_claro = HexColor("#d9b5e9")
    fondo_importe = HexColor("#ead8f4")
    top_tabla = 202 * mm
    bottom_tabla = 54 * mm
    row_h = 3.35 * mm
    max_filas = 40

    if len(liq.items) <= max_filas:
        paginas = [liq.items]
    else:
        capacidad = max_filas - 1
        paginas = [liq.items[i : i + capacidad] for i in range(0, len(liq.items), capacidad)]
    paginas = paginas or [[]]
    total_paginas = len(paginas)
    logo_path = Path(__file__).with_name("GeI_fox.png")

    x_inq, w_inq = 5 * mm, 44 * mm
    x_inm, w_inm = 50 * mm, 46 * mm
    x_det, w_det = 97 * mm, 60 * mm
    x_debe, w_debe = 158 * mm, 23 * mm
    x_haber, w_haber = 182 * mm, 23 * mm

    def txt(x, y, text, size=8, bold=False, align="left", maxw=None):
        font = "Helvetica-Bold" if bold else "Helvetica"
        pdf.setFont(font, size)
        pdf.setFillColor(black)
        value = str(text or "")
        if maxw:
            while value and stringWidth(value, font, size) > maxw:
                value = value[:-1]
        if align == "right":
            pdf.drawRightString(x, y, value)
        elif align == "center":
            pdf.drawCentredString(x, y, value)
        else:
            pdf.drawString(x, y, value)

    def cabecera(numero_pagina: int) -> None:
        if logo_path.exists():
            pdf.drawImage(
                ImageReader(str(logo_path)),
                5 * mm,
                height - 35 * mm,
                width=196 * mm,
                height=28 * mm,
                preserveAspectRatio=True,
                anchor="sw",
                mask="auto",
            )

        txt(107 * mm, height - 12 * mm, "DOCUMENTO NO VALIDO COMO FACTURA", 8, bold=True)
        txt(36 * mm, height - 12 * mm, "PILOTO / NO PRODUCTIVO", 8, bold=True)

        pdf.setStrokeColor(violeta)
        pdf.setFillColor(violeta)
        pdf.rect(107 * mm, height - 28 * mm, 20 * mm, 15 * mm, stroke=1, fill=1)
        pdf.setFont("Helvetica-Bold", 18)
        pdf.setFillColor(white)
        pdf.drawCentredString(117 * mm, height - 23 * mm, "X")
        pdf.line(105 * mm, height - 30 * mm, 204.5 * mm, height - 30 * mm)

        txt(131 * mm, height - 23 * mm, f'N°: {cfg.get("punto_venta", 0):04d}-{int(liq.numero_interno or 0):08d}', 12, bold=True)
        datos = [
            ("FECHA:", liq.fecha),
            ("CUIT N°:", cfg["empresa"]["cuit"]),
            ("ING. BRUTOS N°:", cfg["empresa"].get("ingresos_brutos", "011-000567-4")),
            ("D.R.I. N°:", cfg["empresa"].get("dri", "00301")),
            ("INICIO ACTIVIDADES:", cfg["empresa"].get("inicio_actividades", "01/03/1955")),
        ]
        y = height - 34 * mm
        for label, value in datos:
            txt(107 * mm, y, label, 7, bold=True)
            txt(137 * mm, y, value, 7, bold=True)
            y -= 4.2 * mm

        pdf.setFillColor(violeta_claro)
        pdf.rect(5 * mm, height - 53 * mm, 96 * mm, 8 * mm, stroke=0, fill=1)
        txt(53 * mm, height - 50.5 * mm, "IVA RESPONSABLE INSCRIPTO", 9, bold=True, align="center")

        pdf.setFillColor(violeta_claro)
        pdf.rect(5 * mm, height - 84 * mm, 200 * mm, 30 * mm, stroke=0, fill=1)
        pdf.setStrokeColor(violeta)
        pdf.setLineWidth(3)
        pdf.line(5 * mm, height - 55 * mm, 205 * mm, height - 55 * mm)

        txt(6 * mm, height - 62 * mm, "Razón Social:", 7, bold=True)
        txt(30 * mm, height - 62 * mm, liq.propietario.title(), 8, bold=True, maxw=68 * mm)
        txt(6 * mm, height - 67 * mm, "Domicilio:", 7, bold=True)
        txt(30 * mm, height - 67 * mm, liq.domicilio.title(), 8, bold=True, maxw=68 * mm)
        txt(6 * mm, height - 72 * mm, "Condición IVA:", 7, bold=True)
        txt(30 * mm, height - 72 * mm, liq.condicion_iva, 8, bold=True, maxw=45 * mm)
        txt(105 * mm, height - 62 * mm, "Localidad:", 7, bold=True)
        txt(130 * mm, height - 62 * mm, (liq.localidad or "Santa Fe").title(), 8, bold=True, maxw=70 * mm)
        txt(105 * mm, height - 69 * mm, "CUIT:", 7, bold=True)
        txt(130 * mm, height - 69 * mm, formatear_cuit(liq.cuit), 8, bold=True)
        txt(6 * mm, height - 80 * mm, "Periodo liquidado: ", 7, bold=True)
        txt(30 * mm, height - 80 * mm, formatear_periodo(liq.periodo), 8, bold=True, maxw=68 * mm)
        txt(78 * mm, height - 80 * mm, "Cuenta N°:", 7, bold=True)
        txt(98 * mm, height - 80 * mm, liq.cuenta, 8, bold=True)
        txt(148 * mm, height - 80 * mm, "Compte. N°:", 7, bold=True)
        txt(174 * mm, height - 80 * mm, liq.comprobante, 8, bold=True)
        txt(190 * mm, height - 80 * mm, "Hoja:", 7, bold=True)
        txt(203 * mm, height - 80 * mm, f"{numero_pagina} / {total_paginas}", 8, bold=True, align="right")

        for x, w, title in (
            (x_inq, w_inq, "Inquilino"),
            (x_inm, w_inm, "Inmueble"),
            (x_det, w_det, "Detalle"),
            (x_debe, w_debe, "Debe"),
            (x_haber, w_haber, "Haber"),
        ):
            pdf.setFillColor(violeta)
            pdf.rect(x, top_tabla + 8 * mm, w, 1.4 * mm, stroke=0, fill=1)
            pdf.setFillColor(violeta_claro)
            pdf.rect(x, top_tabla + 1 * mm, w, 7 * mm, stroke=0, fill=1)
            txt(x + w / 2, top_tabla + 3.2 * mm, title, 8, bold=True, align="center")
        pdf.setStrokeColor(violeta)
        pdf.line(5 * mm, top_tabla, 205 * mm, top_tabla)

    for page_number, items in enumerate(paginas, 1):
        cabecera(page_number)
        y = top_tabla - 5 * mm

        if page_number > 1:
            prev_items = [item for page in paginas[: page_number - 1] for item in page]
            prev_debe = sum((item.debe for item in prev_items), Decimal("0"))
            prev_haber = sum((item.haber for item in prev_items), Decimal("0"))
            y = top_tabla - 9 * mm
            pdf.setFillColor(fondo_importe)
            pdf.rect(x_debe, top_tabla - 7.2 * mm, w_debe, row_h + 2.2 * mm, stroke=0, fill=1)
            pdf.rect(x_haber, top_tabla - 7.2 * mm, w_haber, row_h + 2.2 * mm, stroke=0, fill=1)
            txt(x_det + 1 * mm, top_tabla - 4.5 * mm, "Transporte ......................................................................", 6.7)
            txt(x_debe + w_debe - 1 * mm, top_tabla - 4.5 * mm, dinero(prev_debe), 6.7, align="right")
            txt(x_haber + w_haber - 1 * mm, top_tabla - 4.5 * mm, dinero(prev_haber), 6.7, align="right")

        for item in items:
            inmueble = item.detalle if item.vencimiento else ""
            txt(x_inq + 1 * mm, y, item.nombre.title(), 6.7, maxw=w_inq - 2 * mm)
            txt(x_inm + 1 * mm, y, inmueble.title() + (f" [{item.vencimiento}]" if item.vencimiento else ""), 6.7, maxw=w_inm - 2 * mm)
            detalle = item.detalle + (f" ({item.referencia})" if item.referencia else "")
            txt(x_det + 1 * mm, y, detalle, 6.7, maxw=w_det - 2 * mm)

            pdf.setFillColor(fondo_importe)
            pdf.rect(x_debe, y - 1.5 * mm, w_debe, row_h + 1.3 * mm, stroke=0, fill=1)
            pdf.rect(x_haber, y - 1.5 * mm, w_haber, row_h + 1.3 * mm, stroke=0, fill=1)
            if item.debe:
                txt(x_debe + w_debe - 1 * mm, y, dinero(item.debe), 6.7, align="right")
            if item.haber:
                txt(x_haber + w_haber - 1 * mm, y, dinero(item.haber), 6.7, align="right")
            y -= row_h

        if total_paginas > 1 and page_number < total_paginas:
            acumulados = [item for page in paginas[:page_number] for item in page]
            acum_debe = sum((item.debe for item in acumulados), Decimal("0"))
            acum_haber = sum((item.haber for item in acumulados), Decimal("0"))
            y_transporte = bottom_tabla + 1.5 * mm
            pdf.setFillColor(fondo_importe)
            pdf.rect(x_debe, y_transporte - 2.7 * mm, w_debe, row_h + 2.2 * mm, stroke=0, fill=1)
            pdf.rect(x_haber, y_transporte - 2.7 * mm, w_haber, row_h + 2.2 * mm, stroke=0, fill=1)
            txt(x_det + 1 * mm, y_transporte, "Transporte ......................................................................", 6.7)
            txt(x_debe + w_debe - 1 * mm, y_transporte, dinero(acum_debe), 6.7, align="right")
            txt(x_haber + w_haber - 1 * mm, y_transporte, dinero(acum_haber), 6.7, align="right")

        if page_number == total_paginas:
            fy = 48 * mm
            paso_pie = 5 * mm
            x_banda_pie = 96 * mm
            ancho_banda_pie = 109 * mm

            pdf.setFillColor(violeta_claro)
            pdf.rect(x_banda_pie, fy - 3 * mm, ancho_banda_pie, 8 * mm, stroke=0, fill=1)
            txt(98 * mm, fy, "SubTotales", 8, bold=True)
            txt(x_debe + w_debe - 1 * mm, fy, "$ " + dinero(liq.total_debe), 8, bold=True, align="right")
            txt(x_haber + w_haber - 1 * mm, fy, "$ " + dinero(liq.total_haber), 8, bold=True, align="right")

            if "INSCRIP" in liq.condicion_iva.upper():
                pdf.setFillColor(violeta_claro)
                pdf.rect(x_banda_pie, fy - 2 * paso_pie - 3 * mm, ancho_banda_pie, 13 * mm, stroke=0, fill=1)
                txt(98 * mm, fy - paso_pie, "Neto Gravado", 8, bold=True)
                txt(x_debe + w_debe - 1 * mm, fy - paso_pie, "$ " + dinero(liq.total_neto_gravado), 8, bold=True, align="right")
                txt(98 * mm, fy - 2 * paso_pie, "IVA", 8, bold=True)
                txt(x_debe + w_debe - 1 * mm, fy - 2 * paso_pie, "$ " + dinero(liq.total_iva), 8, bold=True, align="right")
                y_total = fy - 4 * paso_pie
            else:
                y_total = fy - 9 * mm

            pdf.setFillColor(violeta_claro)
            pdf.rect(x_banda_pie, y_total - 3.2 * mm, ancho_banda_pie, 9 * mm, stroke=0, fill=1)
            txt(98 * mm, y_total, "TOTAL", 10, bold=True)
            txt(x_haber + w_haber - 1 * mm, y_total, "$ " + dinero_con_signo(liq.total_final), 10, bold=True, align="right")

            forma_pago = " ".join(part for part in (liq.banco.strip(), liq.tipo_cuenta_banco.strip()) if part)
            pagar_a = liq.copropietario.strip() or liq.propietario.strip()
            porcentaje_pagar = liq.porcentaje.strip() or "100%"
            total_pagar = liq.total_copropietario if liq.copropietario else liq.total_final
            if pagar_a:
                y_pagar = fy - 34 * mm
                etiqueta_pagar = "COBRAR A" if total_pagar < 0 else "PAGAR A"
                pdf.setFillColor(violeta_claro)
                pdf.rect(x_banda_pie, y_pagar - 7 * mm, ancho_banda_pie, 13 * mm, stroke=0, fill=1)
                txt(98 * mm, y_pagar, f"{etiqueta_pagar}: {pagar_a.title()} ({porcentaje_pagar})", 7.5, bold=True, maxw=72 * mm)
                if forma_pago:
                    txt(98 * mm, y_pagar - 4 * mm, f"PAGO: {forma_pago}", 7, bold=True, maxw=100 * mm)
                txt(x_haber + w_haber - 1 * mm, y_pagar, "$ " + dinero_con_signo(total_pagar), 8, bold=True, align="right")

            txt(18 * mm, 9 * mm, "Firma y Sello", 7, bold=True)
            pdf.setStrokeColor(violeta)
            pdf.line(5 * mm, 5 * mm, 205 * mm, 5 * mm)
        pdf.showPage()
    pdf.save()
