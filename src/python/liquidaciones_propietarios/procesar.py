from __future__ import annotations

import argparse
import hashlib
import json
import re
from decimal import Decimal
from pathlib import Path
from typing import Any

import motor


ARCHIVOS_PRINCIPALES = (
    "liquida.sf.txt",
    "liquidb.sf.txt",
    "liquida.st.txt",
    "liquidb.st.txt",
)


def json_default(value: Any) -> str:
    if isinstance(value, Decimal):
        return str(value)
    raise TypeError(f"Tipo no serializable: {type(value).__name__}")


def clave_origen(data: dict[str, Any]) -> str:
    componentes = (
        data.get("sede", ""),
        data.get("tipo", ""),
        data.get("periodo", ""),
        data.get("cuenta", ""),
        data.get("comprobante", ""),
        data.get("copropietario", ""),
        data.get("porcentaje", ""),
    )
    return hashlib.sha256("\x1f".join(map(str, componentes)).encode("utf-8")).hexdigest()


def extraer(args: argparse.Namespace) -> int:
    directorio = args.directorio.resolve()
    liquidaciones_dir = directorio / "liquidaciones"
    if not liquidaciones_dir.is_dir():
        liquidaciones_dir = directorio

    config = json.loads(args.config.read_text(encoding="utf-8"))
    encoding = str(config.get("encoding", "cp1252"))
    paths = [liquidaciones_dir / nombre for nombre in ARCHIVOS_PRINCIPALES]
    faltantes = [path.name for path in paths if not path.is_file()]
    if faltantes:
        raise RuntimeError("Faltan archivos de liquidación: " + ", ".join(faltantes))

    periodo = motor.detectar_periodo(paths, encoding)
    if args.periodo and periodo != args.periodo:
        raise RuntimeError(
            f"El período detectado ({periodo}) no coincide con el solicitado ({args.periodo})."
        )

    motor.ENT_LIQ = liquidaciones_dir
    controles = motor.cargar_pliqloc(encoding)
    liquidaciones = motor.parsear_todos(paths, encoding)
    estados: dict[str, int] = {}

    args.salida.parent.mkdir(parents=True, exist_ok=True)
    with args.salida.open("w", encoding="utf-8") as output:
        for liquidacion in liquidaciones:
            control = motor.aplicar_control_pliqloc(liquidacion, controles)
            data = liquidacion.dict()
            data.pop("raw", None)
            data["periodo_aaaamm"] = periodo
            data["cuenta_normalizada"] = re.sub(r"\D", "", liquidacion.cuenta)
            data["clave_origen"] = clave_origen(data)
            data["control_pliqloc"] = control
            output.write(json.dumps(data, ensure_ascii=False, default=json_default) + "\n")
            estado = str(control.get("estado", "SIN_CONTROL"))
            estados[estado] = estados.get(estado, 0) + 1

    print(
        json.dumps(
            {
                "estado": "EXTRACCION_OK",
                "periodo": periodo,
                "liquidaciones": len(liquidaciones),
                "controles": estados,
                "salida": str(args.salida),
            },
            ensure_ascii=False,
        )
    )
    return 0


def decimal(value: Any) -> Decimal:
    return Decimal(str(value or "0"))


def texto(value: Any, predeterminado: str = "") -> str:
    """Convierte datos de PostgreSQL sin transformar NULL en la palabra 'None'."""
    if value is None:
        return predeterminado
    return str(value)


def liquidacion_desde_dict(data: dict[str, Any]) -> motor.Liquidacion:
    items = [
        motor.Item(
            nombre=str(item.get("nombre") or ""),
            detalle=str(item.get("detalle") or ""),
            vencimiento=str(item.get("vencimiento") or ""),
            debe=decimal(item.get("debe")),
            haber=decimal(item.get("haber")),
            referencia=str(item.get("referencia") or ""),
            numero_movimiento_origen=str(item.get("numero_movimiento_origen") or ""),
            fecha_movimiento_origen=str(item.get("fecha_movimiento_origen") or ""),
            archivo_origen=str(item.get("archivo_origen") or ""),
            orden_origen=int(item.get("orden_origen", 0) or 0),
            tipo_movimiento=str(item.get("tipo_movimiento") or ""),
        )
        for item in data.get("items", [])
    ]

    return motor.Liquidacion(
        origen=texto(data.get("origen"), "POSTGRESQL"),
        sede=texto(data.get("sede")),
        tipo=texto(data.get("tipo")),
        fecha=texto(data.get("fecha")),
        periodo=texto(data.get("periodo")),
        propietario=texto(data.get("propietario")),
        domicilio=texto(data.get("domicilio")),
        cp=texto(data.get("cp")),
        localidad=texto(data.get("localidad")),
        provincia=texto(data.get("provincia")),
        condicion_iva=texto(data.get("condicion_iva")),
        cuit=texto(data.get("cuit")),
        cuenta=texto(data.get("cuenta")),
        comprobante=texto(data.get("comprobante")),
        codigo_aux=texto(data.get("codigo_aux")),
        total=decimal(data.get("total")),
        total_bruto=decimal(data.get("total_bruto")),
        banco=texto(data.get("banco")),
        tipo_cuenta_banco=texto(data.get("tipo_cuenta_banco")),
        copropietario=texto(data.get("copropietario")),
        porcentaje=texto(data.get("porcentaje")),
        total_copropietario=decimal(data.get("total_copropietario")),
        total_debe=decimal(data.get("total_debe")),
        total_haber=decimal(data.get("total_haber")),
        total_neto_gravado=decimal(data.get("total_neto_gravado")),
        total_iva=decimal(data.get("total_iva")),
        total_final=decimal(data.get("total_final")),
        items=items,
        raw=[],
        numero_interno=int(data.get("numero_interno") or 0),
    )


def ruta_segura(base: Path, relativa: str) -> Path:
    if not relativa or Path(relativa).is_absolute() or ".." in Path(relativa).parts:
        raise RuntimeError(f"Ruta PDF no válida: {relativa!r}")
    destino = (base / relativa).resolve()
    destino.relative_to(base.resolve())
    return destino


def generar(args: argparse.Namespace) -> int:
    config = json.loads(args.config.read_text(encoding="utf-8"))
    base_salida = args.salida.resolve()
    resultados: list[dict[str, Any]] = []

    with args.entrada.open("r", encoding="utf-8") as source:
        for numero_linea, line in enumerate(source, 1):
            if not line.strip():
                continue
            data = json.loads(line)
            liquidacion = liquidacion_desde_dict(data)
            relativa_solicitada = texto(data.get("pdf_ruta"))
            carpeta = Path(relativa_solicitada).parent
            punto_venta = int(config.get("punto_venta", 0))
            nombre = (
                f"{motor.normalizar_nombre(liquidacion.propietario)} "
                f"L{punto_venta:04d}-{liquidacion.numero_interno:08d}.pdf"
            )
            relativa = str(carpeta / nombre)
            destino = ruta_segura(base_salida, relativa)
            destino.parent.mkdir(parents=True, exist_ok=True)
            motor.generar_pdf(liquidacion, destino, config)
            resultados.append(
                {
                    "id": int(data["id"]),
                    "pdf_ruta": relativa,
                    "bytes": destino.stat().st_size,
                    "items": len(liquidacion.items),
                }
            )

    payload = {
        "estado": "GENERACION_OK",
        "generadas": len(resultados),
        "resultados": resultados,
    }
    if args.resultado:
        args.resultado.parent.mkdir(parents=True, exist_ok=True)
        args.resultado.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
    print(json.dumps(payload, ensure_ascii=False))
    return 0


def parser() -> argparse.ArgumentParser:
    cli = argparse.ArgumentParser(
        description="Adaptador autónomo de liquidaciones de propietarios para gei-web."
    )
    subcommands = cli.add_subparsers(dest="comando", required=True)

    extract = subcommands.add_parser("extraer")
    extract.add_argument("--directorio", type=Path, required=True)
    extract.add_argument("--periodo")
    extract.add_argument("--salida", type=Path, required=True)
    extract.add_argument("--config", type=Path, default=Path(__file__).with_name("config.json"))
    extract.set_defaults(handler=extraer)

    generate = subcommands.add_parser("generar")
    generate.add_argument("--entrada", type=Path, required=True)
    generate.add_argument("--salida", type=Path, required=True)
    generate.add_argument("--resultado", type=Path)
    generate.add_argument("--config", type=Path, default=Path(__file__).with_name("config.json"))
    generate.set_defaults(handler=generar)
    return cli


def main() -> int:
    args = parser().parse_args()
    return int(args.handler(args))


if __name__ == "__main__":
    raise SystemExit(main())
