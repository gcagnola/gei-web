from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict, is_dataclass
from decimal import Decimal
from pathlib import Path
from typing import Any

from gei_importador.config import cargar_config
from gei_importador.errores import ErrorImportador
from gei_importador.main import comparar_cobol, importar_cobol
from gei_importador.pipeline import importar, reconciliar, validar


def _json_default(value: Any) -> Any:
    if isinstance(value, Path):
        return str(value)
    if isinstance(value, Decimal):
        return str(value)
    if is_dataclass(value):
        return asdict(value)
    raise TypeError(f"Object of type {type(value).__name__} is not JSON serializable")


def _resolver_modo(args: argparse.Namespace) -> str:
    if args.confirmar:
        return "confirmar"
    if args.rollback:
        return "rollback"
    return "solo-validar"


def crear_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="gei-importador",
        description="Importador Python para modernizacion GeI / KNG.",
    )
    subparsers = parser.add_subparsers(dest="comando", required=True)

    subparsers.add_parser(
        "validar",
        help="Valida el lote estandar entrada/cobol y entrada/liquidaciones.",
    )
    subparsers.add_parser(
        "importar",
        help="Valida y persiste staging/control en tablas web_ sin modificar tablas heredadas.",
    )
    subparsers.add_parser(
        "reconciliar",
        help="Ejecuta conciliaciones automaticas sobre el lote estandar.",
    )

    importar_cobol_parser = subparsers.add_parser(
        "importar-cobol",
        help="Valida o importa archivos COBOL del repositorio Laravel.",
    )
    importar_cobol_parser.add_argument(
        "--repositorio-id",
        type=int,
        required=True,
        help="Identificador del repositorio o lote registrado por Laravel.",
    )
    importar_cobol_parser.add_argument(
        "--planificar-maestros",
        action="store_true",
        help=(
            "Genera plan de escritura de maestros COBOL contra PostgreSQL sin "
            "insertar, actualizar ni borrar tablas finales."
        ),
    )

    modos = importar_cobol_parser.add_mutually_exclusive_group()
    modos.add_argument(
        "--solo-validar",
        action="store_true",
        help="Parsea y valida sin escribir en PostgreSQL. Es el modo por defecto.",
    )
    modos.add_argument(
        "--rollback",
        action="store_true",
        help="Reservado para fases posteriores.",
    )
    modos.add_argument(
        "--confirmar",
        action="store_true",
        help="Reservado para fases posteriores.",
    )

    comparar_cobol_parser = subparsers.add_parser(
        "comparar-cobol",
        help="Compara los archivos COBOL interpretados contra PostgreSQL sin escribir.",
    )
    comparar_cobol_parser.add_argument(
        "--repositorio-id",
        type=int,
        required=True,
        help="Identificador del repositorio o lote registrado por Laravel.",
    )

    return parser


def ejecutar(args: argparse.Namespace) -> dict[str, Any]:
    config = cargar_config()

    if args.comando == "validar":
        return validar(config)

    if args.comando == "importar":
        return importar(config)

    if args.comando == "reconciliar":
        return reconciliar(config)

    if args.comando == "importar-cobol":
        modo = _resolver_modo(args)
        resultado = importar_cobol(
            config,
            args.repositorio_id,
            modo,
            planificar_maestros=args.planificar_maestros,
        )
        return resultado.to_dict()

    if args.comando == "comparar-cobol":
        resultado = comparar_cobol(config, args.repositorio_id)
        return resultado.to_dict()

    raise ErrorImportador(f"Comando no soportado: {args.comando}")


def main(argv: list[str] | None = None) -> int:
    parser = crear_parser()
    args = parser.parse_args(argv)

    try:
        salida = ejecutar(args)
    except ErrorImportador as exc:
        salida = {
            "error": type(exc).__name__,
            "mensaje": str(exc),
            "escritura_postgresql": False,
        }
        print(
            json.dumps(salida, ensure_ascii=False, indent=2, default=_json_default),
            file=sys.stderr,
        )
        return 2

    print(json.dumps(salida, ensure_ascii=False, indent=2, default=_json_default))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
