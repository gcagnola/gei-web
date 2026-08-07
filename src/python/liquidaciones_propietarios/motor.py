from __future__ import annotations
import csv, json, re, sys, unicodedata
from dataclasses import dataclass, asdict, field
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Iterable
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.pdfgen import canvas
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.lib.colors import HexColor, black, white
from reportlab.lib.utils import ImageReader

MESES={"ENERO":1,"FEBRERO":2,"MARZO":3,"ABRIL":4,"MAYO":5,"JUNIO":6,"JULIO":7,"AGOSTO":8,"SEPTIEMBRE":9,"OCTUBRE":10,"NOVIEMBRE":11,"DICIEMBRE":12}
BASE=Path(__file__).resolve().parents[2]
ENT_LIQ=BASE/'entrada'/'liquidaciones'; ENT_COBOL=BASE/'entrada'/'cobol'; SALIDA=BASE/'salida'; LOGS=BASE/'logs'; ESTADO=BASE/'estado'/'numeracion.json'; CONFIG=BASE/'config'/'config.json'
ARCHIVOS_PRINCIPALES=['liquida.sf.txt','liquidb.sf.txt','liquida.st.txt','liquidb.st.txt']

@dataclass
class Item:
    nombre:str=''
    detalle:str=''
    vencimiento:str=''
    debe:Decimal=Decimal('0')
    haber:Decimal=Decimal('0')
    referencia:str=''
    
    numero_movimiento_origen:str=""
    fecha_movimiento_origen:str=""
    archivo_origen:str=""
    orden_origen:int=0
    tipo_movimiento:str=""

    def dict(self):
        d=asdict(self);
        d['debe']=str(self.debe);
        d['haber']=str(self.haber);
        return d

@dataclass
class Liquidacion:
    origen:str; sede:str; tipo:str; fecha:str=''; periodo:str=''; propietario:str=''; domicilio:str=''; cp:str=''; localidad:str=''; provincia:str=''; condicion_iva:str=''; cuit:str=''; cuenta:str=''; comprobante:str=''; codigo_aux:str=''; total:Decimal=Decimal('0'); total_bruto:Decimal=Decimal('0'); banco:str=''; tipo_cuenta_banco:str=''; copropietario:str=''; porcentaje:str=''; total_copropietario:Decimal=Decimal('0'); total_debe:Decimal=Decimal('0'); total_haber:Decimal=Decimal('0'); total_neto_gravado:Decimal=Decimal('0'); total_iva:Decimal=Decimal('0'); total_final:Decimal=Decimal('0'); items:list[Item]=field(default_factory=list); raw:list[str]=field(default_factory=list); numero_interno:int|None=None
    def dict(self):
        d=asdict(self);
        for k in ('total','total_bruto','total_copropietario','total_debe','total_haber','total_neto_gravado','total_iva','total_final'): d[k]=str(getattr(self,k))
        d['items']=[x.dict() for x in self.items]; return d

def leer(path:Path, enc='cp1252')->str:
    return path.read_bytes().decode(enc,errors='replace').replace('\x00','')

def mitad(linea:str)->str:
    # Los listados vienen duplicados horizontalmente. Detectar dos mitades equivalentes.
    n=len(linea)
    if n>=70:
        centro=n//2
        for corte in range(max(1,centro-8),min(n,centro+9)):
            izq=linea[:corte].rstrip(); der=linea[corte:].strip()
            if izq.strip() and izq.strip()==der:
                return izq
        # En renglones de detalle la segunda copia comienza cerca de la mitad, separada por espacios.
        for corte in range(max(1,centro-12),min(n,centro+13)):
            if linea[corte:corte+4]=='    ':
                izq=linea[:corte].rstrip(); der=linea[corte:].strip()
                if izq and der and (der.startswith(izq[:min(25,len(izq))]) or izq.startswith(der[:min(25,len(der))])):
                    return izq
    return linea.rstrip()

def limpiar_control(s:str)->str:
    return re.sub(r'[\x00-\x08\x0b\x0e-\x1f\x7f]', '', s)

def decimal_ar(s:str)->Decimal:
    s=s.strip().replace('$','').replace(' ','').replace('..','').replace('...','')
    if not s: return Decimal('0')
    neg=s.endswith('DB'); s=s.removesuffix('DB')
    s=s.replace('.','').replace(',','.')
    try: v=Decimal(s)
    except InvalidOperation: return Decimal('0')
    return -v if neg else v

def detectar_periodo(paths:list[Path], enc:str)->str:
    encontrados={}
    rx=re.compile(r'\b('+'|'.join(MESES)+r')\s+(?:DE\s+)?(20\d{2})\b',re.I)
    for p in paths:
        txt=limpiar_control(leer(p,enc)).upper(); vals={(int(y),MESES[m.upper()]) for m,y in rx.findall(txt)}
        # En conceptos aparecen otros meses; priorizar ocurrencias de cabecera repetidas.
        cab=re.findall(r'\b('+'|'.join(MESES)+r')\s+(20\d{2})\b', txt[:5000], re.I)
        if cab: vals={(int(y),MESES[m.upper()]) for m,y in cab}
        if len(vals)==1: encontrados[p.name]=next(iter(vals))
        elif len(vals)>1:
            # elegir el par más repetido en todo el archivo
            pairs=rx.findall(txt); counts={}
            for m,y in pairs: counts[(int(y),MESES[m.upper()])]=counts.get((int(y),MESES[m.upper()]),0)+1
            encontrados[p.name]=max(counts,key=counts.get)
    if not encontrados: raise RuntimeError('No se pudo detectar el período en liquida/liquidb.')
    unicos=set(encontrados.values())
    if len(unicos)!=1: raise RuntimeError('Períodos inconsistentes: '+json.dumps({k:f'{y:04d}{m:02d}' for k,(y,m) in encontrados.items()},ensure_ascii=False))
    y,m=next(iter(unicos)); return f'{y:04d}{m:02d}'

def bloques(path:Path, enc:str)->Iterable[list[str]]:
    txt=limpiar_control(leer(path,enc)).replace('\r','')
    for b in txt.split('\f'):
        ls=[mitad(x) for x in b.split('\n')]
        if any(re.search(r'\d{2}/\d{2}/20\d{2}',x) for x in ls) and any(re.search(r'\b[12]202/\d{5}/\d{2}\b',x) for x in ls): yield ls

def normalizar_condicion_iva(valor:str)->str:
    v=re.sub(r'\s+',' ',(valor or '').replace('.',' ')).strip().upper()
    if 'MONOTRIB' in v: return 'Responsable Monotributo'
    if 'INSCRIP' in v: return 'Responsable Inscripto'
    if 'NO CATEG' in v: return 'No Categorizado'
    if 'EXENTO' in v: return 'Exento'
    return re.sub(r'\s+',' ',valor or '').strip()

def formatear_periodo(valor:str)->str:
    periodo=(valor or '').strip()
    m=re.match(r'^(19|20)(\d{2})(0[1-9]|1[0-2])$', periodo)
    if m:
        meses=('Enero','Febrero','Marzo','Abril','Mayo','Junio',
               'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre')
        return f'{meses[int(m.group(3))-1]}/{m.group(1)}{m.group(2)}'
    m=re.match(r'^\s*([A-ZÁÉÍÓÚÑ]+)\s+(\d{4})\s*$', valor or '', re.I)
    if not m: return valor or ''
    return f'{m.group(1).capitalize()}/{m.group(2)}'

def parsear_bloque(ls:list[str], origen:str)->Liquidacion:
    sede='ST' if '.st.' in origen.lower() else 'SF'
    tipo='B' if origen.lower().startswith('liquidb') else 'A'
    L=Liquidacion(origen,tipo=tipo,sede=sede,raw=ls)
    joined='\n'.join(ls)
    dates=re.findall(r'\b\d{2}/\d{2}/20\d{2}\b',joined); L.fecha=dates[0] if dates else ''
    pm=re.search(r'\b('+'|'.join(MESES)+r')\s+(20\d{2})\b',joined,re.I)
    L.periodo=(pm.group(1).upper()+' '+pm.group(2)) if pm else ''

    # Cabecera repetida en cada página: tomar la primera completa.
    for i,line in enumerate(ls):
        cm=re.search(r'\b([12]202/\d{5}/\d{2})\b',line)
        if not cm: continue
        L.cuenta=cm.group(1)
        before=[x.strip() for x in ls[max(0,i-6):i] if x.strip()]
        cand=[x for x in before if not re.search(r'\d{2}/\d{2}/20\d{2}',x) and not re.match(r'^(Resp|No Categ|Exento)',x,re.I)]
        if cand: L.propietario=cand[0].strip()
        if len(cand)>1: L.domicilio=cand[1].strip()
        # La línea postal contiene CP, localidad y provincia.
        postal=ls[i-2] if i>=2 else ''
        if postal.strip():
            L.cp=postal[7:18].strip()
            L.localidad=postal[18:48].strip()
            L.provincia=postal[48:].strip()
        for x in before:
            if re.search(r'Resp|No Categ|Exento|Monotrib',x,re.I):
                L.condicion_iva=normalizar_condicion_iva(x[:38].strip())
                cuit=re.search(r'\b\d{2}[\.\-]?\d{8}[\.\-]?\d\b',x)
                if cuit: L.cuit=re.sub(r'\D','',cuit.group(0))
        tail=' '.join(ls[i:i+3]); nums=re.findall(r'\b\d{6}\b',tail)
        if nums: L.comprobante=nums[0]
        aux=re.search(r'(\*+\d+)',tail); L.codigo_aux=aux.group(1) if aux else ''
        break

    # Banco, copropietario y porcentaje/importe individual.
    #
    # Casos reales:
    #   POCOVI EDUARDO               PESOS       1.219.998,23 50,000%
    #   PESOS       1.241.409,23
    #
    # Si hay nombre antes de PESOS, representa el destinatario/copropietario.
    # Si no hay porcentaje explícito, se asume 100%.
    for line in ls:
        if re.match(r'^\s*(BANCO|BCO\.|B\.B\.V\.A|Nuevo Bco|PAGO EN EFECTIVO)', line, re.I):
            partes_pago = re.split(r'\s{2,}', line.strip(), maxsplit=1)
            L.banco = partes_pago[0].strip()
            L.tipo_cuenta_banco = partes_pago[1].strip() if len(partes_pago) > 1 else ''

        linea_limpia = re.sub(r'\s+', ' ', line.strip())

        # Formato normal:
        #   POCOVI EDUARDO PESOS 1.219.998,23 50,000%
        #   PESOS 1.241.409,23
        linea_pago = re.search(
            r'^\s*'
            r'(?:(?P<nombre>[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.,&/()-]*?)\s+)?'
            r'PESOS\s+'
            r'(?P<importe>[\d\.]+,\d{2})'
            r'(?:\s+(?P<porcentaje>\d{1,3},\d{1,3}%|\d{1,3}%))?'
            r'\s*$',
            linea_limpia,
            re.I
        )

        # Formato observado en negativos / copropietarios:
        #   BORTOLUZZI M.DEL.C. TER PESOS 12,500%...43.120,06
        # En este caso el porcentaje aparece antes del importe y separado por puntos.
        if not linea_pago:
            linea_pago = re.search(
                r'^\s*'
                r'(?:(?P<nombre>[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s\.,&/()-]*?)\s+)?'
                r'PESOS\s+'
                r'(?P<porcentaje>\d{1,3},\d{1,3}%|\d{1,3}%)'
                r'[\s\.]*'
                r'(?P<importe>[\d\.]+,\d{2})'
                r'\s*$',
                linea_limpia,
                re.I
            )

        if linea_pago:
            nombre_pago = (linea_pago.group('nombre') or '').strip()
            importe_pago = decimal_ar(linea_pago.group('importe'))
            porcentaje_pago = normalizar_porcentaje_pago(
                linea_pago.group('porcentaje')
            )

            # Evitar tomar líneas finales de totales duplicados como copropietario
            # cuando no informan porcentaje ni nombre. En esos casos sólo sirven
            # como referencia del total general y el destinatario sigue siendo
            # el propietario principal.
            if nombre_pago or porcentaje_pago != '100%':
                L.copropietario = nombre_pago
                L.total_copropietario = importe_pago
                L.porcentaje = porcentaje_pago
            elif not L.porcentaje:
                L.porcentaje = '100%'

    # Columnas físicas del listado COBOL (0-based):
    # 0:37 referencia/inquilino, 37:78 detalle/inmueble+vencimiento,
    # 78:96 Debe y 96:114 Haber. No inferir el signo por el texto.
    for line in ls:
        padded=line.ljust(114)
        if re.search(r'Transporte|Total demas co-propietarios|^\s*PESOS|TOTAL',line,re.I):
            continue
        debe=decimal_ar(padded[78:96]); haber=decimal_ar(padded[96:114])
        if debe==0 and haber==0: continue
        izquierda=padded[:78]
        if not re.search(r'[A-Za-zÁÉÍÓÚÑáéíóúñ]',izquierda): continue
        referencia_persona=padded[:37].strip()
        concepto=padded[37:78].strip()
        venc=''
        vm=re.search(r'\b\d{2}/\d{2}/\d{2}\b',concepto)
        es_alquiler=bool(vm and referencia_persona and haber)
        if vm:
            venc=vm.group(0)
            concepto=(concepto[:vm.start()]+concepto[vm.end():]).strip()
        # Toda línea con importe es un movimiento válido, aunque no tenga fecha
        # ni vencimiento en el mismo renglón. La primera columna se conserva como
        # Inquilino/referencia para que ajustes, anticipos, créditos e indemnizaciones
        # no desaparezcan del detalle. El inmueble se informa solo para alquileres.
        nombre=referencia_persona
        inmueble=concepto if es_alquiler else ''
        detalle=concepto
        L.items.append(Item(nombre,inmueble,venc,debe,haber,''))
        # Guardar el concepto completo para el detalle; Item.detalle se usa como inmueble
        # en líneas de alquiler y como concepto en el resto.
        L.items[-1].detalle=detalle

    # Referencias de movimientos, en el mismo orden de las líneas contables.
    refs=[]
    for line in ls:
        refs.extend(re.findall(r'(?<!\d)(\d{6})\s+(\d{2}/\d{2})(?!/)', line))
    anio=L.fecha[-4:] if re.search(r'/20\d{2}$',L.fecha) else ''
    for item,(numero_ref,fecha_ref) in zip(L.items,refs):
        item.referencia=f'{numero_ref} - {fecha_ref}/{anio}' if anio else f'{numero_ref} - {fecha_ref}'

    L.total_debe=sum((x.debe for x in L.items),Decimal('0'))
    L.total_haber=sum((x.haber for x in L.items),Decimal('0'))
    L.total_bruto=L.total_haber

    # Base gravada: comisiones administrativas y comisión sobre impuestos,
    # expensas y servicios. Los créditos gravados (por ejemplo CR.COMIS...) restan.
    def es_gravado(item:Item)->bool:
        t=item.detalle.upper().replace(' + IVA','')
        return ('COMISION P/ADMIN.ALQUILERES' in t or
                'COM.S/IMP,EXPYSERV' in t or
                'CR.COMIS.P/ACRED.INDEB.ALQ.' in t)
    gravados=[x for x in L.items if es_gravado(x)]
    L.total_neto_gravado=sum((x.debe-x.haber for x in gravados),Decimal('0'))
    # El VFP liquida IVA por movimiento y luego suma: redondear cada componente
    # reproduce Vallverdu, Capalbo y Fernández.
    if L.condicion_iva=='Responsable Inscripto':
        iva_debe=sum(((x.debe*Decimal('0.21')).quantize(Decimal('0.01'),rounding=ROUND_HALF_UP) for x in gravados if x.debe),Decimal('0'))
        iva_haber=sum(((x.haber*Decimal('0.21')).quantize(Decimal('0.01'),rounding=ROUND_HALF_UP) for x in gravados if x.haber),Decimal('0'))
        L.total_iva=iva_debe-iva_haber

    # Total neto: Haber - Debe y, para Responsables Inscriptos, menos el IVA discriminado.
    L.total_final=L.total_haber-L.total_debe
    if L.condicion_iva=='Responsable Inscripto':
        L.total_final-=L.total_iva
    L.total=L.total_copropietario if L.total_copropietario else L.total_final
    return L

def parsear_todos(paths:list[Path],enc:str)->list[Liquidacion]:
    """Agrupa las páginas COBOL que pertenecen al mismo comprobante antes de parsear.
    Así Fernández no se convierte en tres liquidaciones y Bourquin no en cuatro.
    """
    out=[]
    for p in paths:
        grupos=[]
        actual=[]; clave_actual=None
        for pagina in bloques(p,enc):
            texto='\n'.join(pagina)
            cuenta=re.search(r'\b[12]202/\d{5}/\d{2}\b',texto)
            # El comprobante interno de seis dígitos está junto a la cuenta/periodo.
            comp=None
            if cuenta:
                pos=next((i for i,x in enumerate(pagina) if cuenta.group(0) in x),0)
                nums=re.findall(r'\b\d{6}\b',' '.join(pagina[pos:pos+3]))
                comp=nums[0] if nums else None
            clave=(cuenta.group(0) if cuenta else '',comp or '')
            if actual and clave!=clave_actual:
                grupos.append(actual); actual=[]
            actual.extend(pagina)
            clave_actual=clave
        if actual: grupos.append(actual)
        for g in grupos:
            liq=parsear_bloque(g,p.name)
            if liq.cuenta and liq.propietario: out.append(liq)
    return out

def normalizar_nombre(s:str)->str:
    s=''.join(c for c in unicodedata.normalize('NFKD',s) if not unicodedata.combining(c)); s=re.sub(r'[\\/:*?"<>|]',' ',s); return re.sub(r'\s+',' ',s).strip().title()

def cargar_estado()->int:
    try:return int(json.loads(ESTADO.read_text())['proximo_numero'])
    except Exception:return 1

def guardar_estado(n:int):
    tmp=ESTADO.with_suffix('.tmp'); tmp.write_text(json.dumps({'proximo_numero':n},indent=2)); tmp.replace(ESTADO)

def dinero(v:Decimal)->str:
    s=f'{abs(v):,.2f}'; return s.replace(',','X').replace('.',',').replace('X','.')+(' DB' if v<0 else '')

def dinero_con_signo(v:Decimal)->str:
    s=f'{abs(v):,.2f}'
    s=s.replace(',','X').replace('.',',').replace('X','.')
    return ('-' if v<0 else '') + s

def _ajustar_texto(c, texto:str, ancho:float, fuente:str='Helvetica', tam:float=7.2)->list[str]:
    texto=(texto or '').strip()
    if not texto: return ['']
    palabras=texto.split()
    lineas=[]; actual=''
    for palabra in palabras:
        candidato=(actual+' '+palabra).strip()
        if stringWidth(candidato,fuente,tam)<=ancho:
            actual=candidato
        else:
            if actual: lineas.append(actual)
            actual=palabra
    if actual: lineas.append(actual)
    return lineas or ['']

def generar_pdf(liq:Liquidacion,dest:Path,cfg:dict):
    """Reproduce la plantilla fija del FRX de propietarios usando coordenadas A4."""
    W,H=A4
    c=canvas.Canvas(str(dest),pagesize=A4)
    violeta=HexColor('#8f159c')
    violeta_claro=HexColor('#d9b5e9')
    fondo_importe=HexColor('#ead8f4')
    margen_x=5*mm
    top_tabla=202*mm
    bottom_tabla=54*mm
    row_h=3.35*mm
    max_filas=40
    
    if len(liq.items)<=max_filas:
        paginas=[liq.items]
    else:
        # Reservar una línea para el transporte en páginas no finales.
        capacidad=max_filas-1
        paginas=[liq.items[i:i+capacidad] for i in range(0,len(liq.items),capacidad)]
    paginas=paginas or [[]]
    total_pag=len(paginas)
    logo_path=Path(__file__).with_name('GeI_fox.png')

    # Coordenadas derivadas del FRX original: 4 columnas de texto/importe.
    x_inq=5*mm; w_inq=44*mm
    x_inm=50*mm; w_inm=46*mm
    x_det=97*mm; w_det=60*mm
    x_debe=158*mm; w_debe=23*mm
    x_haber=182*mm; w_haber=23*mm

    def txt(x,y,text,size=8,bold=False,align='left',maxw=None):
        font='Helvetica-Bold' if bold else 'Helvetica'
        c.setFont(font,size); c.setFillColor(black)
        text=str(text or '')
        if maxw:
            while text and stringWidth(text,font,size)>maxw:
                text=text[:-1]
        if align=='right': c.drawRightString(x,y,text)
        elif align=='center': c.drawCentredString(x,y,text)
        else: c.drawString(x,y,text)

    def cabecera(nro_pagina:int):
        if logo_path.exists():
            c.drawImage(
                ImageReader(str(logo_path)),
                5*mm,
                H-35*mm,
                width=196*mm,
                height=28*mm,
                preserveAspectRatio=True,
                anchor='sw',
                mask='auto'
            )

        txt(
            107*mm,
            H-12*mm,
            'DOCUMENTO NO VALIDO COMO FACTURA',
            8,
            bold=True
        )

        c.setStrokeColor(violeta); c.setFillColor(violeta); c.rect(107*mm,H-28*mm,20*mm,15*mm,stroke=1,fill=1)
        c.setFont('Helvetica-Bold',18); c.setFillColor(white); c.drawCentredString(117*mm,H-23*mm,'X')

        c.line(105*mm, H-30*mm, 204.5*mm, H-30*mm)

        txt(131*mm,H-23*mm,f'N°: {cfg.get("punto_venta",0):04d}-{liq.numero_interno:08d}',12,bold=True)
        datos=[('FECHA:',liq.fecha),('CUIT N°:',cfg['empresa']['cuit']),('ING. BRUTOS N°:',cfg['empresa'].get('ingresos_brutos','011-000567-4')),('D.R.I. N°:',cfg['empresa'].get('dri','00301')),('INICIO ACTIVIDADES:',cfg['empresa'].get('inicio_actividades','01/03/1955'))]
        yy=H-34*mm
        for lab,val in datos:
            txt(107*mm,yy,lab,7,bold=True); txt(137*mm,yy,val,7,bold=True); yy-=4.2*mm
        
        c.setFillColor(violeta_claro); 
        c.rect(5*mm,H-53*mm,96*mm,8*mm,stroke=0,fill=1)

        txt(
            53*mm,
            H-50.5*mm,
            'IVA RESPONSABLE INSCRIPTO',
            9,
            bold=True,
            align='center'
        )

        # Datos propietario
        c.setFillColor(violeta_claro); 
        c.rect(
            5*mm,
            H-84*mm,
            200*mm,
            30*mm,
            stroke=0,
            fill=1
        )
        c.setStrokeColor(violeta)
        c.setLineWidth(3)
        c.line(5*mm, H-55*mm, 205*mm, H-55*mm)
        txt(
            6*mm,
            H-62*mm,
            'Razón Social:',
            7,
            bold=True
        ); 
        txt(
            30*mm,
            H-62*mm,
            liq.propietario.title(),
            8,
            bold=True,
            maxw=68*mm
        )
        txt(6*mm,H-67*mm,'Domicilio:',7,bold=True); txt(30*mm,H-67*mm,liq.domicilio.title(),8,bold=True,maxw=68*mm)
        txt(6*mm,H-72*mm,'Condición IVA:',7,bold=True); txt(30*mm,H-72*mm,liq.condicion_iva,8,bold=True,maxw=45*mm)
        txt(105*mm,H-62*mm,'Localidad:',7,bold=True); txt(130*mm,H-62*mm,(liq.localidad or 'Santa Fe').title(),8,bold=True,maxw=70*mm)
        txt(105*mm,H-69*mm,'CUIT:',7,bold=True); txt(130*mm,H-69*mm,formatear_cuit(liq.cuit),8,bold=True)
        
        txt(
            6*mm,
            H-80*mm,
            'Periodo liquidado: ',
            7,
            bold=True
        )

        txt(
            30*mm,
            H-80*mm,
            formatear_periodo(liq.periodo),
            8,
            bold=True,
            maxw=68*mm
        )

        txt(78*mm,H-80*mm,'Cuenta N°:',7,bold=True); txt(98*mm,H-80*mm,liq.cuenta,8,bold=True)
        txt(148*mm,H-80*mm,'Compte. N°:',7,bold=True); txt(174*mm,H-80*mm,liq.comprobante,8,bold=True)
        txt(190*mm,H-80*mm,'Hoja:',7,bold=True); txt(203*mm,H-80*mm,f'{nro_pagina} / {total_pag}',8,bold=True,align='right')
        # Encabezados tabla
        for x,w,t in ((x_inq,w_inq,'Inquilino'),(x_inm,w_inm,'Inmueble'),(x_det,w_det,'Detalle'),(x_debe,w_debe,'Debe'),(x_haber,w_haber,'Haber')):
            c.setFillColor(violeta);
            c.rect(x,top_tabla+8*mm,w,1.4*mm,stroke=0,fill=1)
            c.setFillColor(violeta_claro);
            c.rect(x,top_tabla+1*mm,w,7*mm,stroke=0,fill=1)
            txt(x+w/2,top_tabla+3.2*mm,t,8,bold=True,align='center')
        c.setStrokeColor(violeta); c.line(5*mm,top_tabla,205*mm,top_tabla)

    for pnum,items in enumerate(paginas,1):
        cabecera(pnum)

        # Transporte de entrada: se dibuja antes de los movimientos para que
        # el fondo no tape ningún importe del detalle.
        if pnum > 1:
            prev = [it for pg in paginas[:pnum-1] for it in pg]
            prev_debe = sum((it.debe for it in prev), Decimal('0'))
            prev_haber = sum((it.haber for it in prev), Decimal('0'))

            y_transporte_inicio = top_tabla - 4.5 * mm
            alto_transporte = row_h + 2.2 * mm

            c.setFillColor(fondo_importe)
            c.rect(
                x_debe,
                y_transporte_inicio - 2.7 * mm,
                w_debe,
                alto_transporte,
                stroke=0,
                fill=1
            )
            c.rect(
                x_haber,
                y_transporte_inicio - 2.7 * mm,
                w_haber,
                alto_transporte,
                stroke=0,
                fill=1
            )

            txt(
                x_det + 1 * mm,
                y_transporte_inicio,
                'Transporte ......................................................................',
                6.7
            )
            txt(
                x_debe + w_debe - 1 * mm,
                y_transporte_inicio,
                dinero(prev_debe),
                6.7,
                align='right'
            )
            txt(
                x_haber + w_haber - 1 * mm,
                y_transporte_inicio,
                dinero(prev_haber),
                6.7,
                align='right'
            )

            y = top_tabla - 9 * mm
        else:
            y = top_tabla - 5 * mm

        for it in items:
            
            
            inmueble=it.vencimiento and it.detalle or ''

            # Inquilino
            txt(x_inq+1*mm,y,it.nombre.title(),6.7,maxw=w_inq-2*mm)
            
            # Inmueble y detalle se dibujan en la misma línea, pero el detalle puede ocupar varias líneas.
            txt(
                x_inm+1*mm,
                y,
                inmueble.title() + (f' [{it.vencimiento}]' if it.vencimiento else ''),
                6.7,
                maxw=w_inm-2*mm
            )

            # Detalle
            detalle_base = (
                formatear_periodo(liq.periodo)
                if it.haber and inmueble and it.detalle.strip() == inmueble.strip()
                else it.detalle
            )
            detalle=detalle_base + (f' ({it.referencia})' if it.referencia else '')
            
            # detalle=it.referencia
            txt(
                x_det+1*mm,
                y,
                detalle,
                6.7,
                maxw=w_det-2*mm
            )

            c.setFillColor(fondo_importe)
            c.rect(
                x_debe,
                y - 1.5 * mm,
                w_debe,
                row_h + 1.3 * mm,
                stroke=0,
                fill=1
            )
            c.rect(
                x_haber,
                y - 1.5 * mm,
                w_haber,
                row_h + 1.3 * mm,
                stroke=0,
                fill=1
            )
            if it.debe: txt(x_debe+w_debe-1*mm,y,dinero(it.debe),6.7,align='right')
            if it.haber: txt(x_haber+w_haber-1*mm,y,dinero(it.haber),6.7,align='right')
            y-=row_h

        # Transporte acumulado al final de una hoja que continúa.
        if total_pag > 1 and pnum < total_pag:
            anteriores=[it for pg in paginas[:pnum] for it in pg]
            acum_debe=sum((it.debe for it in anteriores),Decimal('0'))
            acum_haber=sum((it.haber for it in anteriores),Decimal('0'))

            y_transporte_fin = bottom_tabla + 1.5 * mm
            alto_transporte = row_h + 2.2 * mm

            c.setFillColor(fondo_importe)
            c.rect(
                x_debe,
                y_transporte_fin - 2.7 * mm,
                w_debe,
                alto_transporte,
                stroke=0,
                fill=1
            )
            c.rect(
                x_haber,
                y_transporte_fin - 2.7 * mm,
                w_haber,
                alto_transporte,
                stroke=0,
                fill=1
            )

            txt(
                x_det + 1 * mm,
                y_transporte_fin,
                'Transporte ......................................................................',
                6.7
            )
            txt(
                x_debe + w_debe - 1 * mm,
                y_transporte_fin,
                dinero(acum_debe),
                6.7,
                align='right'
            )
            txt(
                x_haber + w_haber - 1 * mm,
                y_transporte_fin,
                dinero(acum_haber),
                6.7,
                align='right'
            )
        # Pie únicamente en última hoja
        if pnum == total_pag:

            fy = 48 * mm

            # Separación vertical compacta del pie.
            paso_pie = 5 * mm

            # Medidas comunes de las bandas derechas.
            x_banda_pie = 96 * mm
            ancho_banda_pie = 109 * mm

            # Banda violeta clara para SubTotales, sin borde.
            c.setFillColor(violeta_claro)
            c.rect(
                x_banda_pie,
                fy - 3 * mm,
                ancho_banda_pie,
                8 * mm,
                stroke=0,
                fill=1
            )

            txt(
                98 * mm,
                fy,
                'SubTotales',
                8,
                bold=True
            )

            txt(
                x_debe + w_debe - 1 * mm,
                fy,
                '$ ' + dinero(liq.total_debe),
                8,
                bold=True,
                align='right'
            )

            txt(
                x_haber + w_haber - 1 * mm,
                fy,
                '$ ' + dinero(liq.total_haber),
                8,
                bold=True,
                align='right'
            )

            if 'INSCRIP' in liq.condicion_iva.upper():
                # Discriminación fiscal dentro del sector derecho de subtotales.
                c.setFillColor(violeta_claro)
                c.rect(
                    x_banda_pie,
                    fy - 2 * paso_pie - 3 * mm,
                    ancho_banda_pie,
                    13 * mm,
                    stroke=0,
                    fill=1
                )

                txt(
                    98 * mm,
                    fy - paso_pie,
                    'Neto Gravado',
                    8,
                    bold=True
                )

                txt(
                    x_debe + w_debe - 1 * mm,
                    fy - paso_pie,
                    '$ ' + dinero(liq.total_neto_gravado),
                    8,
                    bold=True,
                    align='right'
                )

                txt(
                    98 * mm,
                    fy - 2 * paso_pie,
                    'IVA',
                    8,
                    bold=True
                )

                txt(
                    x_debe + w_debe - 1 * mm,
                    fy - 2 * paso_pie,
                    '$ ' + dinero(liq.total_iva),
                    8,
                    bold=True,
                    align='right'
                )

                y_total = fy - 4 * paso_pie
            else:
                # Mantener una separación corta, pero sin superponer las bandas.
                y_total = fy - 9 * mm

            # Banda violeta clara para TOTAL, sin borde.
            c.setFillColor(violeta_claro)
            c.rect(
                x_banda_pie,
                y_total - 3.2 * mm,
                ancho_banda_pie,
                9 * mm,
                stroke=0,
                fill=1
            )

            txt(
                98 * mm,
                y_total,
                'TOTAL',
                10,
                bold=True
            )

            txt(
                x_haber + w_haber - 1 * mm,
                y_total,
                '$ ' + dinero_con_signo(liq.total_final),
                10,
                bold=True,
                align='right'
            )

            forma_pago = ' '.join(
                x for x in (liq.banco.strip(), liq.tipo_cuenta_banco.strip()) if x
            )
            pagar_a = liq.copropietario.strip() or liq.propietario.strip()
            porcentaje_pagar = liq.porcentaje.strip() or '100%'
            total_pagar = liq.total_copropietario if liq.total_copropietario else liq.total_final

            if pagar_a:
                y_pagar = fy - 34 * mm
                etiqueta_pagar = 'COBRAR A' if total_pagar < 0 else 'PAGAR A'

                # Banda violeta clara para PAGAR A / COBRAR A, sin borde.
                c.setFillColor(violeta_claro)
                c.rect(
                    x_banda_pie,
                    y_pagar - 7 * mm,
                    ancho_banda_pie,
                    13 * mm,
                    stroke=0,
                    fill=1
                )

                txt(
                    98 * mm,
                    y_pagar,
                    f'{etiqueta_pagar}: {pagar_a.title()} ({porcentaje_pagar})',
                    7.5,
                    bold=True,
                    maxw=72 * mm
                )

                if forma_pago:
                    txt(
                        98 * mm,
                        y_pagar - 4 * mm,
                        f'PAGO: {forma_pago}',
                        7,
                        bold=True,
                        maxw=100 * mm
                    )

                txt(
                    x_haber + w_haber - 1 * mm,
                    y_pagar,
                    '$ ' + dinero_con_signo(total_pagar),
                    8,
                    bold=True,
                    align='right'
                )

            dibujar_recuadros_actuo_autorizo(
                c,
                txt,
                violeta,
                violeta_claro,
                5 * mm,
                7 * mm, #desde abajo
                30 * mm,
                14.5 * mm
            )

            txt(65 * mm, 12 * mm, '...................................', 7, bold=True, align='center')
            txt(65 * mm, 9 * mm, 'Firma', 7, bold=True, align='center')
            c.setStrokeColor(violeta)
            c.line(5 * mm, 5 * mm, 205 * mm, 5 * mm)
        c.showPage()
    c.save()


def dibujar_recuadros_actuo_autorizo(c, txt, violeta, violeta_claro, x, y, ancho, alto):
    c.setFillColor(violeta_claro)
    c.setStrokeColor(violeta)
    c.setLineWidth(0.5)

    c.rect(x, y + alto, ancho, alto, stroke=0, fill=1)
    c.rect(x, y, ancho, alto, stroke=0, fill=1)

    txt(x + ancho / 2, y + alto + 11 * mm, 'ACTUÓ', 6, bold=True, align='center')
    txt(x + ancho / 2, y + 11 * mm, 'AUTORIZÓ', 6, bold=True, align='center')

     # Líneas debajo de cada texto
    c.setStrokeColor(violeta)
    c.setLineWidth(0.4)

    c.line(
        x + 2 * mm,
        y + alto + 10 * mm,
        x + ancho - 2 * mm,
        y + alto + 10 * mm
    )

    c.line(
        x + 2 * mm,
        y + 10 * mm,
        x + ancho - 2 * mm,
        y + 10 * mm
    )

def formatear_cuit(cuit:str)->str:
    d=re.sub(r'\D','',cuit or '')
    return f'{d[:2]}-{d[2:10]}-{d[10:]}' if len(d)==11 else cuit

def normalizar_comprobante(valor:str)->str:
    digitos=re.sub(r'\D','',valor or '')
    normalizado=digitos.lstrip('0')
    return normalizado or '0'

def cargar_pliqloc(enc:str)->dict:
    """Lee pliqloc.sf.txt / pliqloc.st.txt e indexa por tipo + cuenta + comprobante.

    La marca DB/CR es la fuente de verdad para el signo:
    - DB => total negativo, COBRAR A
    - CR o sin marca DB => total positivo, PAGAR A
    """
    idx={}
    rx_linea=re.compile(
        r'(?P<fecha>\d{2}/\d{2}/20\d{2})\s+'
        r'(?P<tipo>[AB])\s+'
        r'(?P<comprobante>\d{6,8})\s+'
        r'(?P<cuenta>[12]202/\d{5}/\d{2})\s+'
        r'(?P<resto>.+?)\s+'
        r'(?P<importe>[\d\.]+,\d{2})(?P<marca>DB|CR)?\s*$',
        re.I
    )
    rx_condicion=re.compile(
        r'(Resp\.?\s*Inscripto|Resp\.?\s*Monotributo|No\s+Categorizado|Exento)',
        re.I
    )

    for p in sorted(ENT_LIQ.glob('pliqloc.*.txt')):
        texto=limpiar_control(leer(p,enc)).replace('\r','')
        sede='ST' if '.st.' in p.name.lower() else 'SF'

        for nro_linea,linea in enumerate(texto.split('\n'),1):
            linea=mitad(linea).strip()
            if not linea:
                continue

            m=rx_linea.search(linea)
            if not m:
                continue

            tipo=m.group('tipo').upper()
            comprobante=m.group('comprobante')
            cuenta=m.group('cuenta')
            resto=re.sub(r'\s+',' ',m.group('resto')).strip()
            importe_abs=decimal_ar(m.group('importe')).copy_abs()
            marca=(m.group('marca') or 'CR').upper()
            total_esperado=-importe_abs if marca=='DB' else importe_abs

            cuit=''
            cuit_m=re.search(r'\b\d{11}\b',resto)
            if cuit_m:
                cuit=cuit_m.group(0)
                antes_cuit=resto[:cuit_m.start()].strip()
            else:
                antes_cuit=resto

            condicion=''
            propietario=antes_cuit
            cond_matches=list(rx_condicion.finditer(antes_cuit))
            if cond_matches:
                cond=cond_matches[-1]
                condicion=normalizar_condicion_iva(cond.group(0))
                propietario=antes_cuit[:cond.start()].strip()

            key=(tipo,cuenta,normalizar_comprobante(comprobante))
            control={
                'archivo':p.name,
                'sede':sede,
                'linea':nro_linea,
                'fecha':m.group('fecha'),
                'tipo':tipo,
                'comprobante':comprobante,
                'comprobante_normalizado':normalizar_comprobante(comprobante),
                'cuenta':cuenta,
                'propietario':propietario,
                'condicion_iva':condicion,
                'cuit':cuit,
                'importe':importe_abs,
                'marca':marca,
                'total_esperado':total_esperado,
                'duplicado':False,
            }

            if key in idx:
                idx[key]['duplicado']=True
                control['duplicado']=True

            idx[key]=control

    return idx

def aplicar_control_pliqloc(liq:Liquidacion, idx:dict)->dict:
    """Ajusta total/signo de la liquidación según pliqloc y devuelve fila de validación."""
    comprobante_norm=normalizar_comprobante(liq.comprobante)
    key=(liq.tipo,liq.cuenta,comprobante_norm)
    ctrl=idx.get(key)

    total_antes=liq.total_final
    total_pago_antes=liq.total_copropietario if liq.copropietario else liq.total_final

    fila={
        'tipo':liq.tipo,
        'cuenta':liq.cuenta,
        'comprobante':liq.comprobante,
        'comprobante_normalizado':comprobante_norm,
        'propietario_txt':liq.propietario,
        'total_calculado_antes':str(total_antes),
        'total_pago_antes':str(total_pago_antes),
        'pliqloc_archivo':'',
        'pliqloc_linea':'',
        'pliqloc_fecha':'',
        'pliqloc_comprobante':'',
        'pliqloc_propietario':'',
        'pliqloc_importe':'',
        'pliqloc_marca':'',
        'total_esperado':'',
        'total_final_despues':str(liq.total_final),
        'total_pago_despues':str(total_pago_antes),
        'diferencia_antes':'',
        'estado':'SIN_PLIQLOC',
    }

    if not ctrl:
        return fila

    esperado=ctrl['total_esperado']
    diferencia=total_antes-esperado
    coincide=abs(diferencia)<=Decimal('0.05')
    estado='OK' if coincide else 'AJUSTADO_DESDE_PLIQLOC'
    if ctrl.get('duplicado'):
        estado='DUPLICADO_EN_PLIQLOC'

    # Fuente de verdad: pliqloc. Corrige TOTAL y el signo.
    liq.total_final=esperado
    liq.total=esperado

    # Si el listado COBOL trae destinatario/copropietario o porcentaje explícito,
    # pliqloc corrige el importe y el signo, pero NO debe recalcular el porcentaje.
    # El porcentaje informado en la línea "PESOS" es dato funcional del COBOL
    # y puede venir como 12,500%, 50,000%, etc.
    if liq.copropietario or (liq.porcentaje and liq.porcentaje != '100%'):
        liq.total_copropietario=esperado

    fila.update({
        'pliqloc_archivo':ctrl['archivo'],
        'pliqloc_linea':str(ctrl['linea']),
        'pliqloc_fecha':ctrl['fecha'],
        'pliqloc_comprobante':ctrl['comprobante'],
        'pliqloc_propietario':ctrl['propietario'],
        'pliqloc_importe':str(ctrl['importe']),
        'pliqloc_marca':ctrl['marca'],
        'total_esperado':str(esperado),
        'total_final_despues':str(liq.total_final),
        'total_pago_despues':str(liq.total_copropietario if liq.copropietario else liq.total_final),
        'diferencia_antes':str(diferencia),
        'estado':estado,
    })

    return fila

def normalizar_porcentaje_pago(valor: str | None) -> str:
    """Normaliza el porcentaje de pago sin perder decimales significativos del COBOL.

    En estos listados el porcentaje de copropietario puede venir con tres decimales,
    por ejemplo 12,500% o 50,000%. Ese formato debe conservarse en el PDF.
    """
    if not valor:
        return '100%'

    v = valor.strip().replace(' ', '')

    if not v.endswith('%'):
        v += '%'

    return v

def main():
    cfg=json.loads(CONFIG.read_text()); enc=cfg.get('encoding','cp1252'); LOGS.mkdir(exist_ok=True); SALIDA.mkdir(exist_ok=True)
    paths=[ENT_LIQ/n for n in ARCHIVOS_PRINCIPALES if (ENT_LIQ/n).is_file()]
    if not paths:
        print('ERROR: no se encontraron archivos liquida/liquidb en entrada/liquidaciones',file=sys.stderr); raise SystemExit(2)
    try: periodo=detectar_periodo(paths,enc)
    except Exception as e: print('ERROR:',e,file=sys.stderr); raise SystemExit(2)
    out=SALIDA/periodo; pdfdir=out/'pdf'; pdfdir.mkdir(parents=True,exist_ok=True)
    errores=[]; advert=[]; valid=[]; liqs=parsear_todos(paths,enc); idx=cargar_pliqloc(enc); numero=cargar_estado()
    primer_numero_disponible = numero
    primer_numero_usado = None
    ultimo_numero_usado = None
 
    """
    cuentas_prueba = {
        "1202/06728/07",
        "1202/09059/09"
    }

    liqs = [
        liq
        for liq in liqs
        if liq.cuenta in cuentas_prueba
    ]
    """
    
    with (out/'liquidaciones.jsonl').open('w',encoding='utf8') as jf:
        for liq in liqs:
            fila_validacion=aplicar_control_pliqloc(liq,idx)
            valid.append(fila_validacion)

            if fila_validacion['estado']=='SIN_PLIQLOC':
                advert.append({
                    'archivo':liq.origen,
                    'cuenta':liq.cuenta,
                    'mensaje':f'No encontrado en pliqloc para comprobante {liq.comprobante}'
                })
            elif fila_validacion['estado']=='AJUSTADO_DESDE_PLIQLOC':
                advert.append({
                    'archivo':liq.origen,
                    'cuenta':liq.cuenta,
                    'mensaje':(
                        'Total ajustado desde pliqloc '
                        f'{fila_validacion["pliqloc_archivo"]} '
                        f'comprobante {fila_validacion["pliqloc_comprobante"]}: '
                        f'{fila_validacion["total_calculado_antes"]} -> {fila_validacion["total_esperado"]}'
                    )
                })
            elif fila_validacion['estado']=='DUPLICADO_EN_PLIQLOC':
                advert.append({
                    'archivo':liq.origen,
                    'cuenta':liq.cuenta,
                    'mensaje':f'Duplicado en pliqloc para comprobante {liq.comprobante}'
                })

            liq.numero_interno=numero
            nombre=f'{normalizar_nombre(liq.propietario)} L{cfg.get("punto_venta",0):04d}-{numero:08d}.pdf'; dest=pdfdir/nombre
            if dest.exists() and cfg.get('no_sobrescribir',True):
                advert.append({'archivo':liq.origen,'cuenta':liq.cuenta,'mensaje':'PDF existente, omitido'}); continue
            try:
                generar_pdf(liq, dest, cfg)

                if primer_numero_usado is None:
                    primer_numero_usado = numero

                ultimo_numero_usado = numero

                numero += 1
                guardar_estado(numero)
                jf.write(json.dumps(liq.dict(), ensure_ascii=False) + '\n')
            except Exception as e:
                errores.append({'archivo': liq.origen, 'cuenta': liq.cuenta, 'mensaje': str(e)})
    def csvout(name,rows,fields):
        with (out/name).open('w',encoding='utf8',newline='') as f:
            w=csv.DictWriter(f,fieldnames=fields); w.writeheader(); w.writerows(rows)
    campos_validacion=[
        'tipo',
        'cuenta',
        'comprobante',
        'comprobante_normalizado',
        'propietario_txt',
        'total_calculado_antes',
        'total_pago_antes',
        'pliqloc_archivo',
        'pliqloc_linea',
        'pliqloc_fecha',
        'pliqloc_comprobante',
        'pliqloc_propietario',
        'pliqloc_importe',
        'pliqloc_marca',
        'total_esperado',
        'total_final_despues',
        'total_pago_despues',
        'diferencia_antes',
        'estado',
    ]
    csvout('validacion_pliqloc.csv',valid,campos_validacion)
    csvout('advertencias.csv',advert,['archivo','cuenta','mensaje']); csvout('errores.csv',errores,['archivo','cuenta','mensaje'])
    resumen = {
        'periodo': periodo,
        'archivos': [p.name for p in paths],
        'liquidaciones_detectadas': len(liqs),
        'pdf_generados': len(list(pdfdir.glob('*.pdf'))),
        'advertencias': len(advert),
        'errores': len(errores),
        'primer_numero_disponible': primer_numero_disponible,
        'primer_numero_usado': primer_numero_usado,
        'ultimo_numero_usado': ultimo_numero_usado,
        'proximo_numero': numero,
    }
    (out/'resumen.json').write_text(json.dumps(resumen,ensure_ascii=False,indent=2),encoding='utf8'); print(json.dumps(resumen,ensure_ascii=False,indent=2))
    raise SystemExit(1 if errores else 0)
if __name__=='__main__': main()
