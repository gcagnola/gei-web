from __future__ import annotations

import argparse
import json
from datetime import datetime
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Any

from generar_pdf import Item, Liquidacion, generar_pdf


ROOT = Path(__file__).resolve().parents[2]
CONFIG = Path(__file__).with_name("config.json")


def decimal(valor: Any) -> Decimal:
    return Decimal(str(valor or "0")).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def cuenta_con_barras(cuenta: str) -> str:
    digitos = "".join(ch for ch in str(cuenta or "") if ch.isdigit())
    if len(digitos) == 11:
        return f"{digitos[:4]}/{digitos[4:9]}/{digitos[9:]}"
    return str(cuenta or "")


def fecha_desde_metadata(valor: str) -> str:
    if not valor:
        return ""
    try:
        return datetime.fromisoformat(valor.replace("Z", "+00:00")).strftime("%d/%m/%Y")
    except ValueError:
        return valor[:10]


def validar_json(data: dict) -> None:
    metadata = data.get("metadata") or {}
    encabezado = data.get("encabezado") or {}
    if metadata.get("origen") != "WEB_PILOTO":
        raise RuntimeError("El JSON no declara metadata.origen=WEB_PILOTO.")
    if metadata.get("advertencia") != "EXPERIMENTAL_NO_PRODUCTIVO":
        raise RuntimeError("El JSON no declara metadata.advertencia=EXPERIMENTAL_NO_PRODUCTIVO.")
    if decimal(encabezado.get("diferencia")) != Decimal("0.00"):
        raise RuntimeError("La diferencia contra historico no es cero.")

    suma_items = sum(
        (decimal(item.get("haber")) - decimal(item.get("debe")) for item in data.get("items", [])),
        Decimal("0"),
    )
    total_items = decimal(encabezado.get("total_items"))
    if suma_items != total_items:
        raise RuntimeError(f"La suma de items {suma_items} no coincide con total_items {total_items}.")


def totales_fiscales(data: dict) -> tuple[Decimal, Decimal]:
    neto = Decimal("0.00")
    iva = Decimal("0.00")
    for grupo in data.get("agrupaciones", []):
        codigo = grupo.get("codigo_origen")
        if codigo in {"21", "22"}:
            neto += decimal(grupo.get("total"))
        if codigo == "IVA_21_22":
            iva += decimal(grupo.get("total"))
    return neto, iva


def item_desde_json(raw: dict) -> Item:
    numeros = raw.get("numeros_movimiento_origen") or []
    referencia = ", ".join(str(numero) for numero in numeros[:3])
    if len(numeros) > 3:
        referencia += f" +{len(numeros) - 3}"

    return Item(
        nombre=str(raw.get("inquilino") or ""),
        detalle=str(raw.get("descripcion") or raw.get("codigo_item") or raw.get("codigo") or ""),
        vencimiento=str(raw.get("vencimiento") or ""),
        debe=decimal(raw.get("debe")),
        haber=decimal(raw.get("haber")),
        referencia=referencia,
        numero_movimiento_origen=referencia,
        archivo_origen="liquidacion_web_piloto.json",
        orden_origen=int(raw.get("orden") or 0),
        tipo_movimiento=str(raw.get("codigo") or ""),
    )


def liquidacion_desde_json(data: dict) -> Liquidacion:
    encabezado = data["encabezado"]
    metadata = data["metadata"]
    propietario = encabezado.get("propietario") or {}
    items = [item_desde_json(item) for item in data.get("items", [])]
    total_debe = sum((item.debe for item in items), Decimal("0.00"))
    total_haber = sum((item.haber for item in items), Decimal("0.00"))
    total_neto, total_iva = totales_fiscales(data)
    comprobante = str(encabezado.get("comprobante_historico_numero") or "")
    numero_interno = int(comprobante) if comprobante.isdigit() else 0

    return Liquidacion(
        origen="WEB_PILOTO_JSON",
        sede="SF",
        tipo=str(encabezado.get("comprobante_historico_tipo") or "A"),
        fecha=fecha_desde_metadata(metadata.get("generado_en") or ""),
        periodo=str(encabezado.get("periodo_texto") or encabezado.get("periodo") or ""),
        propietario=str(propietario.get("nombre") or ""),
        domicilio=str(propietario.get("domicilio") or ""),
        cp="3000",
        localidad=str(propietario.get("localidad") or "SANTA FE"),
        provincia=str(propietario.get("provincia") or "SANTA FE"),
        condicion_iva="Responsable Inscripto",
        cuit=str(propietario.get("cuit") or ""),
        cuenta=cuenta_con_barras(str(encabezado.get("cuenta_propietario") or "")),
        comprobante=comprobante,
        codigo_aux="PILOTO",
        total=decimal(encabezado.get("total_items")),
        total_bruto=total_haber,
        banco="",
        tipo_cuenta_banco="",
        copropietario="",
        porcentaje="",
        total_copropietario=Decimal("0.00"),
        total_debe=total_debe,
        total_haber=total_haber,
        total_neto_gravado=total_neto,
        total_iva=total_iva,
        total_final=decimal(encabezado.get("total_items")),
        items=items,
        raw=[],
        numero_interno=numero_interno,
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Genera un PDF piloto ReportLab desde JSON web_*.")
    parser.add_argument("json", type=Path, help="JSON intermedio de liquidacion web piloto.")
    parser.add_argument("--output", type=Path, required=True, help="PDF de salida.")
    args = parser.parse_args()

    data = json.loads(args.json.read_text(encoding="utf-8"))
    validar_json(data)
    cfg = json.loads(CONFIG.read_text(encoding="utf-8"))
    liquidacion = liquidacion_desde_json(data)
    generar_pdf(liquidacion, args.output, cfg)

    result = {
        "estado": "PDF_REPORTLAB_LARAVEL_PILOTO_GENERADO",
        "json": str(args.json),
        "output": str(args.output),
        "bytes": args.output.stat().st_size,
        "items": len(liquidacion.items),
        "total_final": str(liquidacion.total_final),
        "total_debe": str(liquidacion.total_debe),
        "total_haber": str(liquidacion.total_haber),
        "total_neto_gravado": str(liquidacion.total_neto_gravado),
        "total_iva": str(liquidacion.total_iva),
    }
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
