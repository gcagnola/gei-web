from __future__ import annotations

import re
from dataclasses import dataclass, asdict, field
from decimal import Decimal, InvalidOperation
from pathlib import Path

MESES = {
    'ENERO': 1, 'FEBRERO': 2, 'MARZO': 3, 'ABRIL': 4, 'MAYO': 5, 'JUNIO': 6,
    'JULIO': 7, 'AGOSTO': 8, 'SETIEMBRE': 9, 'SEPTIEMBRE': 9,
    'OCTUBRE': 10, 'NOVIEMBRE': 11, 'DICIEMBRE': 12,
}


@dataclass
class DailocItem:
    inmueble: str
    tipo: str
    periodo: str
    partida: str
    importe: Decimal
    porcentaje_propietario: Decimal = Decimal('0')
    importe_propietario: Decimal = Decimal('0')

    def dict(self) -> dict:
        d = asdict(self)
        for k in ('importe', 'porcentaje_propietario', 'importe_propietario'):
            d[k] = str(getattr(self, k))
        return d


@dataclass
class DailocDetalle:
    cuenta: str
    numero: int
    propietario: str = ''
    periodo: str = ''
    sede: str = 'SF'
    items: list[DailocItem] = field(default_factory=list)
    paginas_cobol: int = 0
    total_impuestos_cobol: Decimal = Decimal('0')
    total_comision_iva_cobol: Decimal = Decimal('0')
    total_propietario_cobol: Decimal = Decimal('0')
    origen: str = 'dailoc.SF.txt'

    @property
    def total_impuestos(self) -> Decimal:
        return sum((x.importe for x in self.items), Decimal('0'))

    @property
    def total_propietario(self) -> Decimal:
        return sum((x.importe_propietario for x in self.items), Decimal('0'))

    @property
    def periodo_aaaamm(self) -> str:
        m = re.match(r'^([A-ZÁÉÍÓÚÑ]+)\s+(?:de\s+)?(20\d{2})$', self.periodo.strip(), re.I)
        if not m:
            return ''
        mes = MESES.get(m.group(1).upper())
        return f'{m.group(2)}{mes:02d}' if mes else ''

    def dict(self) -> dict:
        return {
            'cuenta': self.cuenta,
            'numero': self.numero,
            'propietario': self.propietario,
            'periodo': self.periodo,
            'periodo_aaaamm': self.periodo_aaaamm,
            'sede': self.sede,
            'paginas_cobol': self.paginas_cobol,
            'total_impuestos': str(self.total_impuestos),
            'total_impuestos_cobol': str(self.total_impuestos_cobol),
            'total_comision_iva': str(self.total_comision_iva_cobol),
            'total_propietario': str(self.total_propietario),
            'total_propietario_cobol': str(self.total_propietario_cobol),
            'origen': self.origen,
            'items': [x.dict() for x in self.items],
        }


def decimal_ar(valor: str) -> Decimal:
    s = (valor or '').strip().replace('$', '').replace(' ', '')
    if not s:
        return Decimal('0')
    s = s.replace('.', '').replace(',', '.')
    try:
        return Decimal(s)
    except InvalidOperation:
        raise ValueError(f'Importe inválido en DAILOC: {valor!r}')


def _limpiar_control(linea: str) -> str:
    return re.sub(r'[\x00-\x08\x0b\x0e-\x1f\x7f]', '', linea)


def _copia_izquierda(linea: str) -> str:
    """DAILOC imprime dos copias horizontales. La estructura izquierda mide 114 chars."""
    return _limpiar_control(linea.rstrip('\r\n'))[:114].rstrip()


def _parse_item(linea: str) -> DailocItem | None:
    # Layout exacto GIMB98 / DETALLE1 (0-based):
    # 0:4 filler, 4:38 dirección, 38:49 tipo, 49:52 cuota,
    # 52:56 año, 56:74 partida, 74:86 importe, 86:98 filler,
    # 98:104 porcentaje, 104:114 total propietario.
    s = linea.ljust(114)
    tipo = s[38:49].strip()
    cuota = s[49:52].strip()
    anio = s[52:56].strip()
    importe_txt = s[74:86].strip()

    if not tipo or not re.fullmatch(r'\d{2}/', cuota) or not re.fullmatch(r'20\d{2}', anio):
        return None
    if not re.search(r'\d,\d{2}$', importe_txt):
        return None

    return DailocItem(
        inmueble=s[4:38].strip(),
        tipo=tipo,
        periodo=f'{cuota[:2]}/{anio}',
        partida=s[56:74].strip(),
        importe=decimal_ar(importe_txt),
        porcentaje_propietario=decimal_ar(s[98:104]),
        importe_propietario=decimal_ar(s[104:114]),
    )


def _parse_total(linea: str) -> tuple[Decimal, Decimal, Decimal] | None:
    if 'TOTAL.........:' not in linea:
        return None
    nums = re.findall(r'\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}', linea)
    if len(nums) < 3:
        return None
    return tuple(decimal_ar(x) for x in nums[-3:])  # type: ignore[return-value]


def parsear_dailoc(path: Path, encoding: str = 'cp1252') -> list[DailocDetalle]:
    texto = path.read_bytes().decode(encoding, errors='replace').replace('\x00', '')
    paginas = texto.replace('\r', '').split('\f')
    detalles: dict[tuple[str, int], DailocDetalle] = {}
    orden: list[tuple[str, int]] = []

    for pagina in paginas:
        lineas = [_copia_izquierda(x) for x in pagina.split('\n')]
        lineas = [x for x in lineas if x.strip()]
        if not lineas:
            continue

        cuenta = ''
        numero = None
        propietario = ''
        periodo = ''

        # LINEA4: cuenta + correlativo WNUMERO/LNUMERO.
        for i, line in enumerate(lineas[:8]):
            m = re.search(r'\b(\d{9}/\d{2})\b.*?(\*+\d+)\s*$', line)
            if not m:
                continue
            cuenta = m.group(1)
            numero = int(re.sub(r'\D', '', m.group(2)))
            if i + 1 < len(lineas):
                cab = lineas[i + 1]
                propietario = cab[15:52].strip() if len(cab) >= 52 else ''
                pm = re.search(r'\b(' + '|'.join(MESES) + r')\s+de\s+(20\d{2})\b', cab, re.I)
                if pm:
                    periodo = f'{pm.group(1).upper()} de {pm.group(2)}'
            break

        if not cuenta or numero is None:
            continue

        key = (cuenta, numero)
        if key not in detalles:
            detalles[key] = DailocDetalle(
                cuenta=cuenta,
                numero=numero,
                propietario=propietario,
                periodo=periodo,
                sede='ST' if '.st.' in path.name.lower() else 'SF',
                origen=path.name,
            )
            orden.append(key)

        det = detalles[key]
        det.paginas_cobol += 1
        if not det.propietario and propietario:
            det.propietario = propietario
        if not det.periodo and periodo:
            det.periodo = periodo

        for line in lineas:
            item = _parse_item(line)
            if item:
                det.items.append(item)
                continue
            total = _parse_total(line)
            if total:
                det.total_impuestos_cobol += total[0]
                det.total_comision_iva_cobol += total[1]
                det.total_propietario_cobol += total[2]

    return [detalles[k] for k in orden]
