#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from pathlib import Path

from reportlab.lib.colors import HexColor, black, white
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas

PURPLE = HexColor('#8A1B97')
LAV = HexColor('#D8B2E5')
LAV2 = HexColor('#EBD9F1')
TEXT = HexColor('#222222')
MUTED = HexColor('#666666')
GRID = HexColor('#D7D7D7')

W, H = A4


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
    if align == 'right':
        c.drawRightString(x, y, str(s))
    elif align == 'center':
        c.drawCentredString(x, y, str(s))
    else:
        c.drawString(x, y, str(s))


def draw_logo(c, logo: Path | None, x, y, w=72*mm, h=20*mm):
    if logo and logo.is_file():
        try:
            c.drawImage(ImageReader(str(logo)), x, y, width=w, height=h,
                        preserveAspectRatio=True, anchor='sw', mask='auto')
            return
        except Exception:
            pass
    txt(c, x, y + 7*mm, 'GeI', 24, 'Helvetica-Bold', PURPLE)
    txt(c, x + 23*mm, y + 8*mm, 'GUASTAVINO E IMBERT', 10, 'Helvetica-Bold', TEXT)


def header(c, title: str, subtitle: str, logo: Path | None, y_top: float):
    left, right = 14*mm, W-14*mm
    draw_logo(c, logo, left, y_top-24*mm)
    txt(c, right, y_top-8*mm, title, 14, 'Helvetica-Bold', black, 'right')
    if subtitle:
        txt(c, right, y_top-14*mm, subtitle, 8.5, 'Helvetica-Bold', PURPLE, 'right')
    c.setStrokeColor(PURPLE)
    c.setLineWidth(1.6)
    c.line(left, y_top-27*mm, right, y_top-27*mm)
    return y_top-31*mm


def info_band(c, y_top: float, pairs: list[tuple[str, str, str, str]], height_mm=27):
    left, right = 14*mm, W-14*mm
    width = right-left
    h = height_mm*mm
    c.setFillColor(LAV)
    c.rect(left, y_top-h, width, h, fill=1, stroke=0)
    x1 = left+4*mm
    x2 = left+101*mm
    y = y_top-7*mm
    for l1,v1,l2,v2 in pairs:
        txt(c, x1, y, l1, 7, 'Helvetica-Bold')
        txt(c, x1+25*mm, y, v1, 8.3, 'Helvetica-Bold')
        txt(c, x2, y, l2, 7, 'Helvetica-Bold')
        txt(c, x2+20*mm, y, v2, 8.3, 'Helvetica-Bold')
        y -= 8*mm
    return y_top-h


def cortar_segunda_copia(linea: str) -> str:
    linea = linea.expandtabs(8)
    if len(linea) < 95:
        return linea.rstrip()
    # El recibo GIMB42 se imprime duplicado horizontalmente. En los casos reales
    # la segunda copia comienza cerca de la columna 70-80.
    for i in range(58, min(len(linea)-10, 95)):
        izq = linea[:i].rstrip()
        der = linea[i:].lstrip()
        if len(izq.strip()) < 3 or len(der.strip()) < 3:
            continue
        a = re.sub(r'\s+', ' ', izq.strip())
        b = re.sub(r'\s+', ' ', der.strip())
        # quitar controles p0/p1 que pueden diferir en la cabecera
        a = re.sub(r'\s+p\d+$', '', a, flags=re.I)
        b = re.sub(r'\s+p\d+$', '', b, flags=re.I)
        pref = a[:min(16, len(a))]
        if len(pref) >= 6 and b.startswith(pref):
            return izq
    # respaldo: el formato físico del recibo es de aproximadamente 66 columnas por copia
    return linea[:66].rstrip()


@dataclass
class ReceiptItem:
    code: str
    description: str
    amount: Decimal


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


def parse_receipt(texto: str) -> Receipt:
    lines = [cortar_segunda_copia(x) for x in texto.split('\n')]
    lines = [re.sub(r'\s+p\d+\s*$', '', x, flags=re.I).rstrip() for x in lines]
    lines = [x for x in lines if not re.fullmatch(r'\s*C\d+\s*', x, flags=re.I)]
    r = Receipt(items=[])

    # fecha principal: primera fecha del recibo
    for line in lines:
        m = re.search(r'\b\d{2}/\d{2}/\d{4}\b', line)
        if m:
            r.date = m.group(0)
            break

    acc_re = re.compile(r'\b\d{4}/\d{5}/\d{2}\b')
    for idx, line in enumerate(lines):
        m = acc_re.search(line)
        if m:
            r.account = m.group(0)
            r.client = line[:m.start()].strip()
            if idx+1 < len(lines):
                l2 = lines[idx+1]
                md = re.search(r'\b\d{2}/\d{2}/\d{4}\b', l2)
                if md:
                    r.valid_from = md.group(0)
                    r.address = l2[:md.start()].strip()
                else:
                    r.address = l2.strip()
            break

    for line in lines:
        if 'IVA discrim' in line:
            m = re.search(r'\b\d{2}/\d{2}/\d{4}\b', line)
            if m:
                r.valid_to = m.group(0)
        if re.search(r'Resp\.|Monotrib|Inscrip|Exento|No Categ', line, re.I):
            mc = re.search(r'(Resp\.?\s*Monotributo|Resp\.?\s*Inscripto|Responsable\s+Monotributo|Responsable\s+Inscripto|Exento|No\s+Categorizado)', line, re.I)
            if mc:
                r.iva_condition = mc.group(1).replace('Resp.Monotributo', 'Resp. Monotributo')
            cui = re.search(r'\b\d{2}[.\-]?\d{8}[.\-]?\d\b', line)
            if cui:
                digits = re.sub(r'\D', '', cui.group(0))
                if len(digits)==11:
                    r.cuit = f'{digits[:2]}-{digits[2:10]}-{digits[10:]}'
            tail = line[cui.end():] if cui else line
            nr = re.search(r'\b(\d{6})\b', tail)
            if nr:
                r.receipt_no = nr.group(1)

    # importe en letras (dos renglones antes de D E B I T O S)
    debit_idx = next((i for i,x in enumerate(lines) if 'D E B I T O S' in x), None)
    if debit_idx is not None:
        word_lines=[]
        for x in lines[max(0,debit_idx-6):debit_idx]:
            s=x.strip().strip('*')
            if s and ('PESOS' in s.upper() or word_lines):
                if re.search(r'\d', s) and 'PESOS' not in s.upper():
                    continue
                word_lines.append(s)
        r.amount_words=' '.join(word_lines).replace('*******','').strip()

        for line in lines[debit_idx+1:]:
            if not line.strip():
                continue
            if re.search(r'Sr\.Inquilino', line, re.I):
                break
            m = re.match(r'^\s*(?:(\d{2})\s+)?(.+?)\s+([0-9.]+,[0-9]{2})\s*$', line)
            if m:
                desc=m.group(2).strip()
                # ignorar renglones de control, fechas y totales sin descripción
                if not re.search(r'[A-Za-zÁÉÍÓÚÑáéíóúñ]', desc):
                    continue
                if desc.upper() in {'D E B I T O S'}:
                    continue
                r.items.append(ReceiptItem(m.group(1) or '', desc, monto(m.group(3))))

    # texto bancario del pie
    foot=[]
    start=False
    for line in lines:
        if re.search(r'Sr\.Inquilino', line, re.I):
            start=True
        if start and line.strip():
            foot.append(re.sub(r'\s+', ' ', line.strip()))
    r.footer=' '.join(foot)

    return r


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
    account: str=''
    debtor: str=''
    date: str=''
    address: str=''
    interest_type: str=''
    debits: list[DebtRow]=None
    total_debits: Decimal=Decimal('0')
    total_interest: Decimal=Decimal('0')
    total_debits_with_interest: Decimal=Decimal('0')
    credits: list[tuple[str,str,Decimal]]=None
    total_credits: Decimal=Decimal('0')
    liquid_debt: Decimal=Decimal('0')
    rates: list[tuple[str,str]]=None


def parse_debt(texto: str) -> DebtLiquidation:
    d=DebtLiquidation(debits=[],credits=[],rates=[])
    lines=[x.rstrip() for x in texto.replace('\f','\n').split('\n')]
    for line in lines:
        m=re.search(r'CTA\.NRO\.\.:\s*([0-9/]+)',line)
        if m: d.account=m.group(1)
        m=re.search(r'DEUDOR\s*\.\.:\s*(.*?)\s{2,}FECHA:\s*(\d{2}/\d{2}/\d{4})',line)
        if m: d.debtor=m.group(1).strip(); d.date=m.group(2)
        m=re.search(r'DOMICILIO:\s*(.*?)\s{2,}INTERES:\s*(.*)$',line)
        if m: d.address=m.group(1).strip(); d.interest_type=m.group(2).strip()

    debit_start=next((i for i,x in enumerate(lines) if 'D E B I T O S' in x),None)
    total_deb_idx=next((i for i,x in enumerate(lines) if 'TOTAL DEBITOS' in x),None)
    if debit_start is not None and total_deb_idx is not None:
        rx=re.compile(r'^\s*(\d{3})\s+(.+?)\s+(\d{2}/\d{2}/\d{4})\s+([0-9.]+,[0-9]{2})\s+(\d+)\s+([0-9.]+,[0-9]{2})\s*$')
        for line in lines[debit_start+1:total_deb_idx]:
            m=rx.match(line)
            if m:
                d.debits.append(DebtRow(m.group(1),m.group(2).strip(),m.group(3),monto(m.group(4)),m.group(5),monto(m.group(6))))
        nums=re.findall(r'([0-9.]+,[0-9]{2})',lines[total_deb_idx])
        if nums:
            d.total_debits=monto(nums[0])
            if len(nums)>1: d.total_interest=monto(nums[1])
        for line in lines[total_deb_idx+1:total_deb_idx+4]:
            vals=re.findall(r'([0-9.]+,[0-9]{2})',line)
            if len(vals)==1:
                d.total_debits_with_interest=monto(vals[0]); break
    credit_start=next((i for i,x in enumerate(lines) if 'C R E D I T O S' in x),None)
    total_cred_idx=next((i for i,x in enumerate(lines) if 'TOTAL CREDITOS' in x),None)
    if credit_start is not None and total_cred_idx is not None:
        rx2=re.compile(r'^\s*(\d{3})\s+(.+?)\s+([0-9.]+,[0-9]{2})\s*$')
        for line in lines[credit_start+1:total_cred_idx]:
            m=rx2.match(line)
            if m: d.credits.append((m.group(1),m.group(2).strip(),monto(m.group(3))))
        vals=re.findall(r'([0-9.]+,[0-9]{2})',lines[total_cred_idx])
        if vals: d.total_credits=monto(vals[0])
    for line in lines:
        if 'DEUDA LIQUIDA AL' in line:
            vals=re.findall(r'([0-9.]+,[0-9]{2})',line)
            if vals: d.liquid_debt=monto(vals[-1])
    rate_idx=next((i for i,x in enumerate(lines) if x.strip().startswith('TASA (%)')),None)
    if rate_idx is not None:
        for line in lines[rate_idx+1:]:
            m=re.match(r'^\s*([A-Z]{3}/\d{4}):\s*([0-9.,]+)\s*$',line)
            if m: d.rates.append((m.group(1),m.group(2)))
    return d


def draw_receipt_half(c, r: Receipt, y_top: float, label: str, logo: Path | None):
    left,right=14*mm,W-14*mm
    y=header(c,'RECIBO DE LIQUIDACIÓN',label,logo,y_top)
    y=info_band(c,y,[
        ('Inquilino:',r.client,'Cuenta:',r.account),
        ('Domicilio:',r.address,'Fecha:',r.date),
        ('Condición IVA:',r.iva_condition,'Recibo N°:',r.receipt_no),
    ],height_mm=27)

    note_y=y-4.5*mm
    left_note=f'CUIT: {r.cuit}' if r.cuit else ''
    vig=''
    if r.valid_from or r.valid_to:
        vig=f'Vigencia: {r.valid_from or "-"} al {r.valid_to or "-"}'
    txt(c,left,note_y,left_note,6.8)
    txt(c,left+57*mm,note_y,vig,6.8)
    txt(c,right,note_y,'El IVA discriminado no puede computarse como crédito fiscal',6.3,'Helvetica-Oblique',MUTED,'right')

    y=note_y-6.5*mm
    head_h=7.5*mm
    c.setFillColor(LAV); c.rect(left,y-head_h,right-left,head_h,fill=1,stroke=0)
    c.setFillColor(PURPLE); c.rect(left,y-1.6*mm,right-left,1.6*mm,fill=1,stroke=0)
    txt(c,left+9*mm,y-5.5*mm,'Código',6.8,'Helvetica-Bold',align='center')
    txt(c,left+18*mm,y-5.5*mm,'Concepto',6.8,'Helvetica-Bold')
    txt(c,right-2*mm,y-5.5*mm,'Importe',6.8,'Helvetica-Bold',align='right')

    row_y=y-head_h-4.5*mm
    for item in r.items[:7]:
        txt(c,left+9*mm,row_y,item.code,6.7,align='center')
        txt(c,left+18*mm,row_y,item.description[:62],6.8)
        txt(c,right-2*mm,row_y,fmt_monto(item.amount),6.8,align='right')
        row_y-=4.7*mm

    total=sum((x.amount for x in r.items),Decimal('0'))
    total_y=row_y-1.2*mm
    panel_w=72*mm
    c.setFillColor(LAV2); c.rect(right-panel_w,total_y-10*mm,panel_w,10*mm,fill=1,stroke=0)
    txt(c,right-panel_w+4*mm,total_y-6.8*mm,'TOTAL',8.2,'Helvetica-Bold')
    txt(c,right-3*mm,total_y-6.8*mm,'$ '+fmt_monto(total),10,'Helvetica-Bold',align='right')

    words_y=total_y-14.5*mm
    words=(r.amount_words or '').replace('  ',' ').strip()
    if words:
        if not words.upper().startswith('PESOS'):
            words='PESOS '+words
        txt(c,left,words_y,'Son: '+words[:112],6.2,'Helvetica-Bold')
        if len(words)>112:
            txt(c,left,words_y-4*mm,words[112:224],6.2,'Helvetica-Bold')
            words_y-=4*mm
    foot=r.footer or 'Sr. Inquilino: Ud. puede abonar también sus alquileres por transferencias o depósitos bancarios. Consúltenos.'
    txt(c,left,words_y-6*mm,foot[:145],6.1,'Helvetica',MUTED)


def generar_recibo(texto: str, salida: Path, logo: Path | None):
    r=parse_receipt(texto)
    c=canvas.Canvas(str(salida),pagesize=A4)
    c.setTitle(f'Recibo de liquidación {r.receipt_no}'.strip())
    draw_receipt_half(c,r,H-10*mm,'ORIGINAL',logo)
    cut=H/2
    c.saveState(); c.setStrokeColor(HexColor('#999999')); c.setDash(3,3); c.line(12*mm,cut,W-12*mm,cut); c.restoreState()
    txt(c,W/2,cut+1.8*mm,'corte',5.3,'Helvetica',MUTED,'center')
    draw_receipt_half(c,r,cut-3*mm,'DUPLICADO',logo)
    c.save()


def generar_liquidacion(texto: str, salida: Path, logo: Path | None):
    d=parse_debt(texto)
    c=canvas.Canvas(str(salida),pagesize=A4)
    c.setTitle('Liquidación de deuda')
    left,right=14*mm,W-14*mm
    y=header(c,'LIQUIDACIÓN DE DEUDA','',logo,H-12*mm)
    y=info_band(c,y,[
        ('Deudor:',d.debtor,'Cuenta:',d.account),
        ('Domicilio:',d.address,'Fecha:',d.date),
        ('Interés:',d.interest_type,'',''),
    ],height_mm=27)

    y-=8*mm
    # Débitos
    txt(c,left,y,'DÉBITOS',9,'Helvetica-Bold',PURPLE)
    y-=3*mm
    cols=[left,left+13*mm,left+92*mm,left+119*mm,left+145*mm,right]
    hh=8*mm
    c.setFillColor(LAV); c.rect(left,y-hh,right-left,hh,fill=1,stroke=0)
    c.setFillColor(PURPLE); c.rect(left,y-1.7*mm,right-left,1.7*mm,fill=1,stroke=0)
    txt(c,(cols[0]+cols[1])/2,y-6*mm,'N°',7,'Helvetica-Bold',align='center')
    txt(c,cols[1]+1.5*mm,y-6*mm,'Detalle',7,'Helvetica-Bold')
    txt(c,(cols[2]+cols[3])/2,y-6*mm,'Vencim.',7,'Helvetica-Bold',align='center')
    txt(c,cols[4]-2*mm,y-6*mm,'Val. nom.',7,'Helvetica-Bold',align='right')
    txt(c,(cols[4]+cols[5]-14*mm)/2,y-6*mm,'Días',7,'Helvetica-Bold',align='center')
    txt(c,right-2*mm,y-6*mm,'Intereses',7,'Helvetica-Bold',align='right')
    y-=hh+5*mm
    for row in d.debits:
        txt(c,(cols[0]+cols[1])/2,y,row.no,7,align='center')
        txt(c,cols[1]+1.5*mm,y,row.detail[:34],7)
        txt(c,(cols[2]+cols[3])/2,y,row.due,7,align='center')
        txt(c,cols[4]-2*mm,y,fmt_monto(row.nominal),7,align='right')
        txt(c,cols[4]+9*mm,y,row.days,7,align='center')
        txt(c,right-2*mm,y,fmt_monto(row.interest),7,align='right')
        y-=5.4*mm
    c.setStrokeColor(GRID); c.line(left,y+2*mm,right,y+2*mm)
    txt(c,left+2*mm,y-2*mm,'TOTAL DÉBITOS',7.5,'Helvetica-Bold')
    txt(c,cols[4]-2*mm,y-2*mm,fmt_monto(d.total_debits),7.5,'Helvetica-Bold',align='right')
    txt(c,right-2*mm,y-2*mm,fmt_monto(d.total_interest),7.5,'Helvetica-Bold',align='right')
    y-=10*mm

    if d.credits:
        txt(c,left,y,'CRÉDITOS',9,'Helvetica-Bold',PURPLE); y-=3*mm
        c.setFillColor(LAV); c.rect(left,y-hh,right-left,hh,fill=1,stroke=0)
        c.setFillColor(PURPLE); c.rect(left,y-1.7*mm,right-left,1.7*mm,fill=1,stroke=0)
        txt(c,left+7*mm,y-6*mm,'N°',7,'Helvetica-Bold',align='center')
        txt(c,left+17*mm,y-6*mm,'Detalle',7,'Helvetica-Bold')
        txt(c,right-2*mm,y-6*mm,'Importe',7,'Helvetica-Bold',align='right')
        y-=hh+5*mm
        for no,desc,val in d.credits:
            txt(c,left+7*mm,y,no,7,align='center')
            txt(c,left+17*mm,y,desc[:60],7)
            txt(c,right-2*mm,y,fmt_monto(val),7,align='right')
            y-=5.4*mm
        c.setStrokeColor(GRID); c.line(left,y+2*mm,right,y+2*mm)
        txt(c,left+2*mm,y-2*mm,'TOTAL CRÉDITOS',7.5,'Helvetica-Bold')
        txt(c,right-2*mm,y-2*mm,fmt_monto(d.total_credits),7.5,'Helvetica-Bold',align='right')
        y-=12*mm

    panel_h=17*mm
    c.setFillColor(LAV2); c.rect(left,y-panel_h,right-left,panel_h,fill=1,stroke=0)
    txt(c,left+5*mm,y-6.5*mm,f'DEUDA LÍQUIDA AL {d.date}',9,'Helvetica-Bold',PURPLE)
    txt(c,right-5*mm,y-11.5*mm,'$ '+fmt_monto(d.liquid_debt),16,'Helvetica-Bold',black,'right')
    y-=panel_h+8*mm

    if d.rates:
        txt(c,left,y,'TASA (%)',7.5,'Helvetica-Bold',MUTED)
        x=left+22*mm
        for period,rate in d.rates:
            txt(c,x,y,f'{period}: {rate}',7.2); x+=38*mm
    c.save()


def main() -> int:
    ap=argparse.ArgumentParser()
    ap.add_argument('--raw',required=True)
    ap.add_argument('--tipo',required=True,choices=['LIQUIDACION_DEUDA','RECIBO_LIQUIDACION'])
    ap.add_argument('--salida',required=True)
    ap.add_argument('--logo')
    args=ap.parse_args()
    raw=Path(args.raw); out=Path(args.salida); out.parent.mkdir(parents=True,exist_ok=True)
    logo=Path(args.logo) if args.logo else None
    texto=limpiar_texto(leer_raw(raw))
    if args.tipo=='RECIBO_LIQUIDACION': generar_recibo(texto,out,logo)
    else: generar_liquidacion(texto,out,logo)
    return 0

if __name__=='__main__':
    raise SystemExit(main())
