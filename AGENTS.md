# Laravel GeI — Guastavino e Imbert Administración

Estamos trabajando en el proyecto **Laravel GeI — Guastavino e Imbert Administración**.

Antes de dar comandos o proponer cambios, respetar este contexto técnico y no suponer contenedores, rutas o herramientas diferentes.

## Estructura del proyecto

La raíz del proyecto es:

```text
~/proyectos/gei-web
```

El código Laravel está en:

```text
~/proyectos/gei-web/src
```

Docker Compose está en:

```text
~/proyectos/gei-web/docker-compose.yml
```

La carpeta `src` se monta dentro de los contenedores como:

```text
/var/www/html
```

## Contenedores Docker

### gei-app

Contiene:

- PHP 8.4
- Apache
- Laravel
- Composer
- extensiones PostgreSQL
- código montado en `/var/www/html`

Publica:

```text
Puerto 80 de la VM -> puerto 80 del contenedor
```

No tiene Node.js ni npm.

## Acceso web

La aplicación se publica directamente en el puerto 80 de la máquina virtual:

```text
http://IP-DE-LA-VM/
```

### gei-node

Contiene:

- Node.js 22
- npm
- Vite

Trabaja en:

```text
/var/www/html
```

Publica:

```text
localhost:5173 -> contenedor:5173
```

No ejecutar npm dentro de `gei-app`.

## Comandos y permisos

### Artisan

Para Artisan usar siempre el alias:

```bash
gei-artisan <comando>
```

El alias configurado en el host equivale a:

```bash
docker exec -it \
    --user "$(id -u):$(id -g)" \
    -e HOME=/tmp \
    -w /var/www/html \
    gei-app php artisan
```

La definición completa del alias es:

```bash
alias gei-artisan='docker exec -it --user "$(id -u):$(id -g)" -e HOME=/tmp -w /var/www/html gei-app php artisan'
```

Este alias es obligatorio porque:

- ejecuta Artisan dentro del contenedor `gei-app`;
- usa el UID y GID del usuario actual del host;
- evita crear archivos pertenecientes a `root`;
- define `HOME=/tmp`, un directorio escribible dentro del contenedor;
- fija `/var/www/html` como directorio de trabajo.

No reemplazarlo por:

```bash
docker exec -it gei-app php artisan ...
```

porque puede generar archivos con propietario `root` y provocar problemas de permisos.

Ejemplos:

```bash
gei-artisan route:list
gei-artisan migrate
gei-artisan optimize:clear
```

Si el alias no estuviera disponible en una terminal nueva:

```bash
source ~/.bashrc
type gei-artisan
```

### npm y Vite

Para npm y Vite usar siempre `gei-node`:

```bash
docker exec -it gei-node npm install
docker exec -it gei-node npm run build
docker exec -it gei-node npm run dev -- --host 0.0.0.0
```

No ejecutar npm directamente en el host ni dentro de `gei-app`.

### Composer

Para Composer usar `gei-app`, respetando UID y GID:

```bash
docker exec -it \
    --user "$(id -u):$(id -g)" \
    -e HOME=/tmp \
    -w /var/www/html \
    gei-app composer install
```

No ejecutar Composer como `root`.

No crear contenedores temporales para Node, PHP o Composer sin revisar primero `docker-compose.yml`.

## Stack confirmado

- Laravel 13.8
- PHP 8.4
- Apache
- PostgreSQL
- Node.js 22
- Vite 8
- Bootstrap 5.3
- Sass
- Tailwind 4 instalado, pero no utilizado actualmente
- Blade
- JavaScript nativo
- sin Alpine.js
- sin Livewire

No introducir Tailwind, Alpine.js, Livewire, React, Vue u otro framework sin justificarlo y acordarlo previamente.

## Autenticación

El login institucional ya está implementado y funcionando.

Rutas principales:

```text
GET  /login
POST /login
POST /logout
GET  /
```

Controlador:

```text
App\Http\Controllers\Auth\LoginController
```

Vista de acceso:

```text
resources/views/auth/login.blade.php
```

No reemplazar ni regenerar la autenticación sin revisar previamente:

- `src/routes/web.php`
- `src/app/Http/Controllers/Auth/LoginController.php`
- `src/app/Models/User.php`
- `src/resources/views/auth/login.blade.php`

## Identidad visual

La estética debe seguir el sitio institucional de Guastavino e Imbert.

Nombre del sistema:

```text
Guastavino e Imbert — Administración
```

Frase institucional:

```text
La tranquilidad de un siglo de experiencia
```

Criterios:

- aplicar la estética a todo el sistema administrativo;
- fondo blanco o gris muy claro;
- encabezado superior con menú horizontal desplegable;
- menú responsive tipo hamburguesa en pantallas pequeñas;
- color principal violeta institucional;
- tipografía Montserrat;
- densidad media;
- tablas ligeramente compactas;
- priorizar el ancho útil del contenido para tablas y listados;
- diseño responsive;
- sin fotografías;
- login dividido entre panel institucional violeta y formulario blanco;
- componentes Blade reutilizables.

Colores base:

```css
--gei-primary: #962aa8;
--gei-primary-hover: #7f288d;
--gei-primary-active: #70217e;
--gei-secondary: #aa54b8;
--gei-text: #394041;
--gei-background: #f5f5f7;
--gei-surface: #ffffff;
```

Recursos gráficos:

```text
public/images/gei/favicon.png
public/images/gei/logo-horizontal-blanco.webp
public/images/gei/logo-compacto.webp
```

## Convenciones de base de datos

Todas las tablas nuevas creadas para el sistema web deben comenzar con:

```text
web_
```

Todos los campos nuevos creados para el sistema web también deben comenzar con:

```text
web_
```

No renombrar tablas ni columnas heredadas.

No modificar estructuralmente tablas heredadas salvo agregar campos nuevos con prefijo `web_`.

Toda modificación de base de datos debe quedar documentada mediante migraciones o scripts SQL reproducibles.

Antes de crear migraciones, revisar la estructura real existente.

## Modernización GeI / KNG

Este proyecto forma parte de la modernización integrada de **GeI / KNG**.

El objetivo funcional es reemplazar el circuito actual:

```text
Archivos COBOL/TXT
    ↓
KNG
    ↓
DBF
    ↓
GeI
    ↓
PostgreSQL
    ↓
PDF
```

por el circuito nuevo:

```text
Archivos COBOL/TXT
    ↓
Importador Python
    ↓
PostgreSQL existente
    ↓
Generador Python
    ↓
PDF
```

No reproducir los DBF como almacenamiento intermedio. Hay que reproducir la lógica funcional de KNG y GeI, pero persistiendo directamente en la base PostgreSQL existente `db_gei`.

### Repositorios involucrados

La solución integrada usa al menos estos repositorios:

```text
~/proyectos/gei-web
~/proyectos/gei-liquidaciones-python
```

`gei-web` contiene Laravel, la interfaz administrativa, la carga de archivos, estados visibles para el usuario, listados y descarga o envío de PDFs.

`gei-liquidaciones-python` contiene el motor Python existente de liquidaciones y debe alojar o integrarse con el nuevo importador Python modular.

Antes de modificar cualquiera de los dos repositorios, revisar sus archivos reales, README, scripts, estructura actual y este `AGENTS.md`.

### Fuentes de verdad

No inventar tablas finales, columnas ni reglas de negocio.

Usar como fuente de verdad, en este orden:

1. La estructura real de `db_gei`.
2. El archivo `db_gei_estructura.sql`.
3. Los fuentes Visual FoxPro de GeI.
4. Los fuentes y resultados de auditoría de KNG.
5. Los archivos COBOL/TXT reales.
6. El generador Python de liquidaciones existente.

Antes de crear código que escriba en PostgreSQL, revisar `db_gei_estructura.sql`.

Tablas confirmadas del circuito de liquidaciones:

```text
liquidaciones_de_clientes
liquidaciones_de_clientes_items
liquidaciones_de_clientes_facturas_de_venta
```

También están involucradas, entre otras:

```text
clientes
inmuebles
inmuebles_propietarios
contratos
contratos_inmuebles
contratos_inquilinos
```

No limitarse a esas tablas. Revisar en GeI todas las operaciones:

```text
INSERT
UPDATE
DELETE
SQLEXEC
SQLSTRINGCONNECT
SQLCONNECT
```

Documentar cualquier otra tabla afectada antes de escribir código de persistencia.

### Fuentes Visual FoxPro a revisar

Revisar el paquete de auditoría de GeI y buscar específicamente:

```text
liquidaciones_de_clientes
liquidaciones_de_clientes_items
liquidaciones_de_clientes_facturas_de_venta
Cursor_Liquidaciones
Cursor_Items
Numero_Detalle
Numero_de_Comprobante
SQLEXEC
INSERT INTO
UPDATE
DELETE FROM
```

El formulario principal de liquidaciones está relacionado con:

```text
generar liquidacion
```

También revisar:

```text
PROGRAMAS/gei.sc2
CLASES/gei.vc2
```

GeI se conecta a PostgreSQL por ODBC y reutiliza un handle equivalente a:

```foxpro
GeI.hnd_db
```

El nuevo módulo Python no debe usar ODBC. Debe usar conexión nativa PostgreSQL, preferentemente `psycopg 3`.

### Procesos requeridos

El nuevo módulo debe resolver tres procesos.

#### Proceso 1: importar archivos COBOL

KNG actualmente lee:

```text
PROPIETAR.TXT
INQUILINO.TXT
CTACTEPRO.TXT
INQCTACTE.TXT
```

y los convierte a DBF. El nuevo importador Python debe leer directamente esos archivos y volcar la información a PostgreSQL.

Relación preliminar:

```text
PROPIETAR.TXT
→ propietarios y relaciones asociadas

INQUILINO.TXT
→ inquilinos, inmuebles, contratos y relaciones

CTACTEPRO.TXT
→ cuenta corriente y movimientos de propietarios

INQCTACTE.TXT
→ cuenta corriente acumulada y movimientos de inquilinos
```

Verificar en los fuentes de KNG cómo interpreta exactamente cada archivo. No asumir posiciones, longitudes ni significados sin revisar el código o comparar contra los DBF generados por KNG.

#### Proceso 2: importar archivos de liquidaciones

Archivos relacionados:

```text
dailoc.SF.txt
dailoc2.SF.txt

liquida.sf.txt
liquida.st.txt

liquidb.sf.txt
liquidb.st.txt

pliqloc.*
```

Significado funcional confirmado:

```text
dailoc.SF.txt
→ detalle de impuestos y servicios de Santa Fe

dailoc2.SF.txt
→ detalle de impuestos y servicios de Santo Tomé
```

```text
liquida.sf.txt
→ liquidaciones de Santa Fe

liquida.st.txt
→ liquidaciones de Santo Tomé
```

Contienen alquiler, gastos resumidos y forma o destino del pago al propietario.

```text
liquidb.sf.txt
→ liquidaciones tipo B de Santa Fe

liquidb.st.txt
→ liquidaciones tipo B de Santo Tomé
```

Contienen además número de referencia y fecha de referencia.

Investigar y documentar si esas referencias corresponden a pago, movimiento, recibo u otro comprobante. No presentar esa relación como confirmada hasta encontrar evidencia.

#### Proceso 3: generar PDF desde PostgreSQL

El generador PDF existente actualmente parsea TXT directamente. Debe refactorizarse para:

```text
PostgreSQL
    ↓
Liquidacion + Items
    ↓
generador PDF
```

El generador no debe leer archivos COBOL ni TXT. Debe consultar los datos ya importados y validados desde PostgreSQL.

No reescribir el generador desde cero. Separar:

```text
parser TXT
repositorio PostgreSQL
generador PDF
```

El generador debe recibir un objeto `Liquidacion` completo:

```python
liquidacion = repository.obtener_liquidacion(id)
generar_pdf(liquidacion, destino, config)
```

### Integración Laravel / Python

Laravel ya tiene una pantalla que permite subir archivos, guardarlos en un repositorio y registrar el lote o carga.

El importador Python debe poder ejecutarse desde Laravel usando un identificador de repositorio o lote.

Interfaz CLI esperada:

```bash
python -m gei_importador.cli procesar \
    --repositorio-id 123
```

También debe soportar comandos separados:

```bash
python -m gei_importador.cli importar-cobol \
    --repositorio-id 123

python -m gei_importador.cli importar-liquidaciones \
    --repositorio-id 123

python -m gei_importador.cli generar-pdf \
    --repositorio-id 123
```

Laravel será responsable de:

- subir los archivos;
- conservarlos en el repositorio;
- iniciar el proceso Python;
- mostrar estados;
- mostrar advertencias y errores;
- listar liquidaciones;
- descargar o enviar PDF.

Python será responsable de:

- lectura;
- parseo;
- validación;
- normalización;
- persistencia;
- generación de PDF;
- logs técnicos.

### Arquitectura Python esperada

Crear una estructura modular semejante a:

```text
src/gei_importador/
├── __init__.py
├── cli.py
├── main.py
├── config.py
├── database.py
├── models.py
├── errores.py
├── resultado.py
│
├── cobol/
│   ├── __init__.py
│   ├── importador.py
│   ├── propietar.py
│   ├── inquilino.py
│   ├── ctactepro.py
│   └── inqctacte.py
│
├── liquidaciones/
│   ├── __init__.py
│   ├── importador.py
│   ├── parser.py
│   ├── dailoc.py
│   ├── validador.py
│   ├── repository.py
│   └── pdf_generator.py
│
└── repositories/
    ├── importaciones.py
    ├── clientes.py
    ├── inmuebles.py
    ├── contratos.py
    ├── movimientos.py
    └── liquidaciones.py
```

Los nombres pueden ajustarse si el proyecto ya tiene una estructura mejor, pero deben quedar claramente separadas estas responsabilidades:

- parser de archivos;
- modelos;
- validaciones;
- repositorios PostgreSQL;
- coordinación;
- generación PDF;
- integración CLI.

### Fases de implementación

No implementar todo de una sola vez.

Primero entregar un informe breve con:

1. árbol actual del proyecto;
2. archivos relevantes encontrados;
3. tablas PostgreSQL involucradas;
4. flujo actual KNG;
5. flujo actual GeI;
6. propuesta de módulos;
7. riesgos;
8. plan de implementación.

Después implementar únicamente la primera fase:

```text
PROPIETAR.TXT
INQUILINO.TXT
modo solo-validar
```

La primera versión debe:

1. localizar los archivos dentro del repositorio Laravel;
2. validar que existan;
3. detectar encoding;
4. leer registros de ancho fijo;
5. interpretar cada registro;
6. generar objetos Python tipados;
7. registrar errores de formato;
8. producir un resumen;
9. funcionar en modo `solo-validar`;
10. no escribir todavía en las tablas definitivas.

Ejemplo:

```bash
python -m gei_importador.cli importar-cobol \
    --repositorio-id 123 \
    --solo-validar
```

Salida esperada, con valores reales surgidos del procesamiento:

```json
{
  "repositorio_id": 123,
  "modo": "solo-validar",
  "archivos": {
    "PROPIETAR.TXT": {
      "registros": 0,
      "validos": 0,
      "errores": 0
    },
    "INQUILINO.TXT": {
      "registros": 0,
      "validos": 0,
      "errores": 0
    }
  },
  "escritura_postgresql": false
}
```

Entregar:

- archivos nuevos;
- archivos modificados;
- comandos exactos;
- ejemplo de salida;
- pruebas;
- procedimiento de rollback;
- lista de pendientes.

No modificar tablas definitivas sin mostrar primero el mapeo exacto:

```text
archivo COBOL
→ campo interpretado
→ campo DBF original
→ columna PostgreSQL
```

Segunda fase: persistir `PROPIETAR.TXT` e `INQUILINO.TXT` en:

```text
clientes
inmuebles
inmuebles_propietarios
contratos
contratos_inmuebles
contratos_inquilinos
```

La lógica debe surgir del código GeI. Reconstruir clave de búsqueda, criterio de existencia, campos actualizables, campos que no deben sobrescribirse, relaciones, controles de duplicidad y comportamiento frente a bajas o ausencias.

Tercera fase: implementar:

```text
CTACTEPRO.TXT
INQCTACTE.TXT
```

`INQCTACTE.TXT` es un acumulado histórico, no un archivo exclusivo del mes. No asumir que todos sus movimientos corresponden al período de liquidación actual.

Conservar como mínimo:

- cuenta;
- fecha;
- número de movimiento;
- concepto;
- debe;
- haber;
- período;
- orden;
- archivo de origen.

Cuarta fase: importar:

```text
dailoc.SF.txt
dailoc2.SF.txt
liquida.sf.txt
liquida.st.txt
liquidb.sf.txt
liquidb.st.txt
pliqloc.*
```

Debe detectar sede, tipo, período, agrupar páginas, identificar cuenta, propietario y comprobante, interpretar ítems y referencias, calcular Debe, Haber, Neto Gravado, IVA y Total, validar contra `pliqloc`, guardar cabecera e ítems, y asociar facturas si corresponde.

### Reglas de duplicidad, transacciones e idempotencia

No usar solamente la cuenta como clave.

Analizar la clave real usando:

```text
sede
tipo
período
cuenta
número de comprobante
número interno
archivo de origen
```

GeI consulta liquidaciones existentes por:

```text
Numero_de_Comprobante
```

y controla movimientos procesados usando referencias equivalentes a:

```text
Numero_Detalle
```

Verificar la lógica exacta en los fuentes.

Cada unidad lógica debe ser transaccional.

Para liquidaciones:

```text
una liquidación
    ├── cabecera
    ├── ítems
    └── facturas relacionadas
```

Si falla un ítem:

- rollback completo de esa liquidación;
- registrar error;
- continuar con la siguiente.

Para importación de clientes/contratos, definir unidades transaccionales razonables según la lógica original.

El proceso debe poder reejecutarse sin duplicar datos. Debe conservar:

- hash SHA-256 del archivo;
- identificador de lote;
- nombre;
- tamaño;
- fecha;
- tipo;
- sede;
- período;
- estado;
- cantidad de registros;
- errores.

No procesar dos veces el mismo archivo exacto sin una opción explícita de reproceso.

### Modos de ejecución

Implementar:

```text
--solo-validar
--rollback
--confirmar
```

`--solo-validar`:

- parsea;
- valida;
- genera logs;
- no escribe.

`--rollback`:

- ejecuta SQL;
- valida transacciones;
- revierte todo al finalizar.

`--confirmar`:

- realiza escritura real.

No permitir escritura real por defecto.

### Configuración y base de datos desde Python

Usar variables de entorno:

```text
PGHOST
PGPORT
PGDATABASE
PGUSER
PGPASSWORD
```

No guardar credenciales en el código.

Usar conexión PostgreSQL con `psycopg`.

Requisitos:

- SQL parametrizado;
- `Decimal` para importes;
- nunca `float`;
- fechas con tipos de fecha;
- encoding controlado;
- logs estructurados;
- type hints;
- excepciones específicas.

### Estados y logs

Usar estados equivalentes a:

```text
PENDIENTE
VALIDANDO
IMPORTANDO_COBOL
IMPORTANDO_LIQUIDACIONES
GENERANDO_PDF
FINALIZADO
FINALIZADO_CON_ADVERTENCIAS
ERROR
```

Adaptarlos a las tablas Laravel existentes si ya hay campos equivalentes.

No crear tablas duplicadas sin revisar primero el esquema Laravel.

Registrar por lote:

- archivo;
- número de línea;
- tipo de registro;
- cuenta;
- propietario o inquilino;
- error;
- advertencia;
- acción ejecutada;
- tabla afectada;
- clave encontrada o insertada.

Generar salida JSON apta para que Laravel la interprete.

### Pruebas

Crear pruebas unitarias con muestras reales de:

```text
PROPIETAR.TXT
INQUILINO.TXT
CTACTEPRO.TXT
INQCTACTE.TXT
liquida
liquidb
dailoc
pliqloc
```

Las pruebas deben validar:

- posiciones;
- encoding;
- importes;
- fechas;
- cuentas;
- agrupación de páginas;
- referencias;
- totales;
- idempotencia;
- rollback.

## Home y menú

El Home posterior al login ya está implementado.

Archivos agregados o modificados:

```text
src/routes/web.php
src/resources/views/inicio.blade.php
src/resources/views/layouts/app.blade.php
src/resources/views/components/menu-principal.blade.php
src/resources/views/modulo-en-construccion.blade.php
```

Se crearon las carpetas:

```text
src/resources/views/layouts/
src/resources/views/components/
```

El Home incluye:

- menú superior horizontal y jerárquico;
- submenús desplegables en escritorio;
- menú vertical tipo hamburguesa en pantallas pequeñas;
- encabezado con usuario autenticado;
- botón para cerrar sesión;
- diseño responsive con Bootstrap 5.3;
- marcado visual de la opción activa;
- pantallas provisorias para módulos todavía no desarrollados.

Las nuevas pantallas deben extender:

```blade
@extends('layouts.app')
```

No duplicar el layout ni el menú dentro de cada vista.

El menú principal está en:

```text
src/resources/views/components/menu-principal.blade.php
```

La navegación debe conservar estas decisiones:

- ocupar todo el ancho disponible sin reservar una columna lateral;
- mantener la jerarquía y las rutas actuales;
- mostrar claramente la sección activa;
- cerrar los desplegables al seleccionar una opción;
- no volver al menú lateral salvo que se acuerde previamente.

## Menú actual

```text
0-Archivo
    0:1-Clientes
        0:1:0-Clientes
        0:1:1-Cuenta Corriente
    0:2-Inmuebles
    0:3-Conceptos
    0:4-Proveedores
    0:5-Contratos

1-Propietarios
    1:1-Administrador de Liquidaciones
    1:2-Generar Liquidación de Propietarios
    1:3-Consultas de saldos de Propietarios

2-Inquilinos
    2:1-Administrador de Liquidaciones
    2:2-Generar Liquidación de Inquilinos
    2:3-Consultas de saldos de Inquilinos

3-Compras
    3:1-Facturas de Proveedores
    3:2-Cuenta Corriente

4-Contabilidad
    4:1-Plan de Cuentas
    4:2-Caja Diaria
    4:3-Libro de IVA Ventas

5-Opciones
    5:1-Usuarios
    5:2-Seteos
```

## Forma de trabajo

Antes de indicar comandos o modificar archivos:

1. Revisar los archivos reales relacionados con la tarea.
2. Revisar en qué contenedor corresponde ejecutar cada comando.
3. No asumir que una herramienta está instalada en `gei-app`.
4. Usar `gei-artisan` para Artisan.
5. Usar `gei-node` para npm y Vite.
6. Usar Composer dentro de `gei-app` con UID/GID del usuario.
7. Explicar un paso por vez, porque el usuario está comenzando con Laravel y Docker.
8. No dar varios caminos alternativos cuando uno solo sea suficiente.
9. No modificar arquitectura, Docker o configuración sin revisar primero los archivos reales.
10. Mantener las decisiones ya tomadas y señalar cualquier contradicción antes de avanzar.
11. Hacer cambios mínimos y localizados.
12. No reestructurar partes ajenas a la tarea.
13. No sobrescribir archivos completos si alcanza con una modificación puntual.
14. Mantener Bootstrap 5.3, Blade y JavaScript nativo.
15. No reemplazar el layout ni el menú sin revisar primero los archivos existentes.
16. Validar rutas, vistas y sintaxis después de cada cambio.
17. Mostrar un resumen exacto de los archivos modificados.
18. No crear archivos de ejemplo innecesarios.
19. No borrar código existente sin explicar el motivo.
20. No modificar archivos ajenos a la tarea.

## Entrega de cambios

Cuando se generen modificaciones fuera de Codex:

- entregar solamente los archivos nuevos o modificados;
- conservar sus rutas relativas desde `src/`;
- indicar antes cuáles archivos serán creados o reemplazados;
- no entregar el proyecto completo;
- no incluir `.env`, dependencias, cachés ni archivos ajenos a la tarea.

Cuando se trabaje directamente con Codex en VS Code:

- modificar directamente el repositorio abierto;
- revisar `AGENTS.md` antes de comenzar;
- informar qué archivos se tocarán;
- no generar paquetes comprimidos salvo pedido expreso;
- al finalizar, resumir los cambios y los comandos de validación ejecutados.

## Estado actual consolidado

Actualmente están terminados:

- entorno Docker del proyecto;
- login institucional;
- autenticación y cierre de sesión;
- Home posterior al login;
- layout administrativo;
- encabezado superior con menú horizontal jerárquico;
- submenús desplegables en escritorio;
- menú hamburguesa responsive;
- vistas provisorias para módulos pendientes.

El siguiente módulo debe desarrollarse sobre esta base, sin reemplazar el login, el layout ni el menú existente.
