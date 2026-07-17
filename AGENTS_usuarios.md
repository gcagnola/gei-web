Estamos trabajando en el proyecto **Laravel GeI — Guastavino e Imbert Administración**.

Necesito que implementes la primera etapa del módulo **Clientes**, migrando funcionalmente una pantalla heredada de Visual FoxPro a Laravel.

No inventes rutas, tablas, columnas, relaciones ni tecnologías. Antes de modificar, revisá el proyecto existente y respetá su estructura, convenciones visuales y autenticación actual.

## Instrucción prioritaria: revisar `AGENTS.md`

Antes de inspeccionar, proponer o modificar cualquier archivo, localizá y leé completamente el archivo:

```text
AGENTS.md
```

Revisá también si existen otros archivos `AGENTS.md` dentro de subdirectorios del proyecto.

Las instrucciones de `AGENTS.md` son obligatorias y tienen prioridad para definir:

* estructura del proyecto;
* comandos permitidos;
* convenciones de código;
* arquitectura;
* estilos;
* pruebas;
* restricciones;
* flujo de trabajo;
* archivos que pueden o no modificarse.

No asumas que este prompt reemplaza a `AGENTS.md`. Este prompt define el alcance funcional del módulo Clientes, mientras que `AGENTS.md` define cómo debe trabajarse dentro del repositorio.

En el diagnóstico inicial, indicá:

1. qué archivos `AGENTS.md` encontraste;
2. qué alcance tiene cada uno;
3. qué instrucciones relevantes aplican al módulo Clientes;
4. si existe alguna contradicción entre este pedido y `AGENTS.md`.

Si existe una contradicción, no implementes el punto conflictivo hasta informarlo.

## Etapa 1: inspección

Antes de modificar:

1. localizar y leer el `AGENTS.md` de la raíz;
2. localizar y leer cualquier `AGENTS.md` aplicable dentro de subdirectorios;
3. resumir las instrucciones relevantes encontradas;
4. revisar estructura actual del proyecto;
5. revisar rutas;
6. revisar layout principal;
7. revisar autenticación;
8. revisar menú lateral o navegación;
9. revisar módulos ya implementados;
10. revisar conexión a PostgreSQL;
11. verificar si ya existen modelos o controladores relacionados;
12. verificar convenciones de nombres y estilos;
13. revisar si existe código parcial del módulo Clientes.

Informar brevemente qué encontraste antes de implementar.

---

# 1. Entorno técnico obligatorio

## Estructura

Raíz del proyecto:

```text
~/proyectos/gei-web
```

Código Laravel:

```text
~/proyectos/gei-web/src
```

Docker Compose:

```text
~/proyectos/gei-web/docker-compose.yml
```

La carpeta `src` se monta dentro de los contenedores como:

```text
/var/www/html
```

## Contenedores

* `gei-app`: PHP 8.4, Apache, Laravel, Composer y acceso a PostgreSQL.
* `gei-node`: Node.js 22, npm y Vite.

## Stack

* Laravel 13.x
* PostgreSQL
* Blade
* Bootstrap 5.3
* Sass
* JavaScript nativo
* Vite 8

## Restricciones

* No usar Livewire.
* No usar Alpine.js.
* No usar Tailwind para este módulo.
* No reemplazar Bootstrap.
* No crear una SPA.
* No modificar estructuralmente las tablas heredadas.
* No crear migraciones sobre las tablas existentes.
* No renombrar tablas ni columnas.
* No alterar claves primarias, secuencias, índices ni relaciones.
* No agregar paquetes sin una necesidad concreta y sin informarlo.
* No ejecutar comandos de npm en el host.
* No asumir que Node está instalado en `gei-app`.

## Comandos

Para Artisan:

```bash
gei-artisan ...
```

Para npm/Vite:

```bash
docker exec -it gei-node npm ...
```

Para Composer, ejecutar dentro de `gei-app`, respetando UID/GID y trabajando en:

```text
/var/www/html
```

---

# 2. Objetivo funcional

Implementar el módulo **Clientes** con este alcance:

1. Consulta y búsqueda de clientes.
2. Alta de clientes.
3. Modificación de clientes.
4. Consulta de contratos de alquiler vinculados al cliente.
5. Consulta de inmuebles vinculados a esos contratos.

Los contratos e inmuebles son únicamente de lectura en esta etapa.

---

# 3. Funcionalidades expresamente excluidas

No implementar:

* alta de contratos;
* modificación de contratos;
* eliminación de contratos;
* alta o modificación de inmuebles;
* gestión de propietarios;
* gestión de inquilinos dentro de contratos;
* modificación de porcentajes de participación;
* cuenta corriente;
* recibos;
* intereses;
* liquidaciones;
* cuentas bancarias;
* proveedores;
* eliminación física de clientes;
* migraciones de tablas heredadas.

---

# 4. Diseño general de la pantalla

La pantalla debe conservar la lógica funcional del sistema FoxPro, pero adaptada correctamente a una aplicación web moderna.

Usar una interfaz Blade + Bootstrap 5.3.

## Distribución sugerida

### Panel izquierdo

* título “Clientes”;
* buscador;
* filtros de tipo;
* listado paginado;
* identificación clara del cliente seleccionado;
* botón para crear cliente.

### Panel derecho

* datos principales del cliente;
* botón para modificar;
* secciones o tarjetas con:

  * identificación;
  * domicilio;
  * contacto;
  * información fiscal;
  * información laboral;
  * contratos de alquiler.

En pantallas pequeñas los paneles deben apilarse.

No copiar literalmente la estética antigua de FoxPro. Mantener la identidad visual ya existente en Laravel GeI.

Antes de diseñar, revisar layouts, componentes, navegación, estilos, formularios y pantallas existentes del proyecto.

---

# 5. Tabla principal `clientes`

La tabla ya existe.

## Clave primaria

```text
codigo_cliente
```

## Secuencia

La clave se genera automáticamente mediante:

```text
personas_codigo_persona_seq
```

No asignar manualmente `codigo_cliente`.

## Columnas relevantes

```text
codigo_cliente
doctipo
docnro
apellidos
nombres
domicilio
provincia
departamento
localidad
cp
caractel
telefonos
celular
fax
email
nacionalidad
cuit
condicion_iva
personeria
id_prop
id_inq
profesion
lugar_de_trabajo
razon_social
```

La tabla contiene más columnas vinculadas a proveedores, saldos y cuenta corriente. No incluirlas en esta primera etapa ni sobrescribirlas accidentalmente.

## Características de la tabla heredada

Muchas columnas son `CHAR`, no `VARCHAR`.

Por eso:

* aplicar `TRIM()` al consultar;
* evitar mostrar espacios de relleno;
* normalizar los valores antes de validar;
* no cambiar los tipos de datos;
* no guardar `NULL` en columnas `NOT NULL`;
* usar los valores predeterminados heredados o cadenas vacías cuando corresponda.

---

# 6. Modelo `Cliente`

Crear o adaptar un modelo Eloquent para la tabla `clientes`.

Configuración esperada:

```php
protected $table = 'clientes';
protected $primaryKey = 'codigo_cliente';
public $timestamps = false;
```

Definir correctamente:

* `$fillable` o `$guarded`;
* casts necesarios;
* accessors solo cuando realmente ayuden;
* relaciones de lectura con contratos e inmuebles.

No asumir nombres convencionales de claves.

Crear un atributo calculado o método para el nombre visible:

* si `personeria = Física`:

  * mostrar `apellidos, nombres`;
* si `personeria = Jurídica`:

  * mostrar `razon_social`;
* si los datos heredados son inconsistentes o están vacíos:

  * aplicar un fallback razonable sin modificar el registro.

Ejemplo de fallback:

1. razón social;
2. apellidos y nombres;
3. CUIT;
4. documento;
5. `Cliente #codigo_cliente`.

---

# 7. Valores reales utilizados por la base

## Personería

Valores actuales:

```text
Física
Jurídica
```

## Tipo de documento

Valores existentes:

```text
CUIT
DNI
LC
LE
```

También existen registros antiguos con tipo de documento vacío.

En formularios nuevos, no ofrecer vacío como opción válida.

## Condición de IVA

Valores actuales:

```text
Categorizado
Consumidor Final
Exento
Responsable Inscripto
Responsable Monotributo
Sujeto no Categorizado
```

Valor predeterminado:

```text
Consumidor Final
```

## Nacionalidad

Valor predeterminado:

```text
Argentina
```

## Ubicación predeterminada

```text
Provincia: Santa Fe
Localidad: Santa Fe
```

---

# 8. Reglas según personería

## Persona física

Campos obligatorios:

* `personeria`;
* `apellidos`;
* `nombres`;
* `doctipo`;
* `docnro`.

Tipos de documento permitidos:

```text
DNI
LC
LE
```

Para registros nuevos:

* `razon_social = ''`.

No borrar automáticamente datos heredados durante una modificación si el usuario no cambió la personería.

## Persona jurídica

Campos obligatorios:

* `personeria`;
* `razon_social`;
* `cuit`.

En registros nuevos:

* usar `doctipo = CUIT`;
* guardar el número identificatorio en `docnro`, sin puntos ni guiones;
* guardar el CUIT formateado o normalizado en `cuit`, según la convención existente del proyecto;
* `apellidos = ''`;
* `nombres = ''`.

Antes de definir cómo persistir el CUIT, revisar los datos existentes y la lógica actual del proyecto.

Existen datos heredados inconsistentes, por ejemplo clientes jurídicos con:

* `doctipo = DNI`;
* apellidos;
* nombres;
* razón social;
* CUIT.

La aplicación debe mostrarlos y permitir editarlos sin corregirlos destructivamente de manera automática.

---

# 9. Regla de duplicidad documental

La validación de duplicidad debe seguir exactamente esta lógica.

## Primera instancia

Buscar otros clientes con el mismo `docnro`, después de:

* aplicar `TRIM`;
* quitar espacios exteriores;
* normalizar el valor recibido;
* excluir el propio `codigo_cliente` en una modificación.

Consulta conceptual:

```sql
SELECT codigo_cliente, doctipo, docnro
FROM clientes
WHERE TRIM(docnro) = :docnro
  AND codigo_cliente <> :codigo_cliente_actual;
```

## Segunda instancia

Solo si existe uno o más registros con ese `docnro`, comprobar si ya existe la misma combinación:

```text
doctipo + docnro
```

Consulta conceptual:

```sql
SELECT codigo_cliente
FROM clientes
WHERE TRIM(doctipo) = :doctipo
  AND TRIM(docnro) = :docnro
  AND codigo_cliente <> :codigo_cliente_actual;
```

## Resultado

Se considera duplicado únicamente si ya existe la misma combinación exacta:

```text
doctipo + docnro
```

Ejemplo permitido:

```text
DNI + 12345678
LC  + 12345678
```

Ejemplo no permitido:

```text
DNI + 12345678
DNI + 12345678
```

Para persona jurídica, la combinación normal será:

```text
CUIT + número de CUIT
```

No crear un índice único ni modificar la base. La validación debe realizarse desde Laravel.

Implementar esta lógica en una clase de validación, servicio o regla personalizada reutilizable. No duplicar la consulta en varios controladores.

---

# 10. Normalización de documentos

Antes de validar o persistir:

## `docnro`

* quitar espacios exteriores;
* para DNI, LC y LE, quitar puntos y espacios internos;
* conservar únicamente caracteres válidos para el tipo;
* no convertir un valor vacío heredado en otro valor automáticamente durante una edición.

## `cuit`

* permitir que el usuario escriba con o sin guiones;
* normalizar para comparación;
* validar que tenga 11 dígitos cuando corresponda;
* conservar una representación consistente al guardar;
* no alterar en masa registros existentes.

No implementar validación matemática del dígito verificador salvo que ya exista una función equivalente en el proyecto. En caso de agregarla, dejarla claramente aislada y con pruebas.

---

# 11. Alta y modificación

Crear:

* formulario de alta;
* formulario de modificación;
* Form Requests independientes;
* mensajes de validación en castellano.

## Seguridad y consistencia

* usar transacciones;
* usar consultas parametrizadas mediante Eloquent o Query Builder;
* no concatenar SQL manualmente;
* aplicar autorización y middleware de autenticación existente;
* preservar datos no incluidos en el formulario;
* nunca realizar `update` masivo sobre columnas ajenas al módulo.

## Alta

Usar el mecanismo normal de PostgreSQL/Eloquent para obtener `codigo_cliente` generado por secuencia.

No calcular:

```text
MAX(codigo_cliente) + 1
```

## Modificación

Excluir el propio cliente del control de duplicidad.

No sobrescribir columnas contables, saldos, proveedor, CBU ni cuenta corriente.

---

# 12. Búsqueda y listado

Implementar búsqueda sin diferenciar mayúsculas y minúsculas.

En PostgreSQL puede utilizarse `ILIKE`.

Buscar sobre:

* `codigo_cliente`;
* `apellidos`;
* `nombres`;
* `razon_social`;
* `docnro`;
* `cuit`;
* `email`;
* `telefonos`;
* `celular`.

Aplicar `TRIM()` sobre campos `CHAR`.

La búsqueda por nombres debe permitir encontrar una persona física escribiendo:

```text
apellido nombre
```

o:

```text
nombre apellido
```

No cargar todos los clientes en memoria.

Usar paginación del lado del servidor.

Cantidad inicial sugerida:

```text
25 registros por página
```

Preservar búsqueda, filtros y cliente seleccionado al cambiar de página cuando sea razonable.

---

# 13. Filtros de clientes

Incluir como mínimo:

```text
Todos
Propietarios
Inquilinos
```

La clasificación debe obtenerse de las relaciones reales.

## Propietario

Un cliente es propietario si aparece en:

```text
inmuebles_propietarios.codigo_cliente
```

No depender exclusivamente de `clientes.id_prop`, porque es un identificador heredado y puede contener datos antiguos.

## Inquilino

Un cliente es inquilino si aparece en:

```text
contratos_inquilinos.codigo_cliente
```

No depender exclusivamente de `clientes.id_inq`.

Usar `EXISTS`, relaciones o subconsultas eficientes.

No generar consultas N+1.

---

# 14. Contratos del cliente

Los contratos son de lectura.

## Tablas

```text
contratos
contratos_inquilinos
contratos_inmuebles
inmuebles
```

## Relaciones

```text
clientes.codigo_cliente
    -> contratos_inquilinos.codigo_cliente

contratos_inquilinos.codigo_contrato
    -> contratos.codigo_contrato

contratos.codigo_contrato
    -> contratos_inmuebles.codigo_contrato

contratos_inmuebles.codigo_inmueble
    -> inmuebles.codigo_inmueble
```

## Campos relevantes de `contratos`

```text
codigo_contrato
fecha_contrato
plazo
fecha_fin
importe_inicial
fecha_inicio
archivo_contrato
observaciones
numero_de_contrato
cotizacion_dolar
```

## Campos relevantes de `contratos_inquilinos`

```text
codigo_contrato
codigo_cliente
porcentaje_participacion
id_inq
```

## Campos relevantes de `contratos_inmuebles`

```text
codigo_contrato
codigo_inmueble
```

## Datos a mostrar

Por cada contrato:

* código interno;
* número de contrato;
* fecha de inicio;
* fecha de finalización;
* importe inicial;
* porcentaje de participación;
* inmueble o inmuebles vinculados;
* domicilio;
* localidad;
* tipo de inmueble, cuando esté disponible;
* estado calculado:

  * vigente;
  * vencido;
  * futuro.

## Consideraciones históricas

Muchos contratos antiguos tienen:

```text
numero_de_contrato = 0
importe_inicial = 0
```

No ocultarlos.

Cuando `numero_de_contrato` sea vacío o cero, mostrar:

```text
Contrato #codigo_contrato
```

No interpretar `importe_inicial = 0` como dato faltante.

Un cliente puede tener muchos contratos. Mostrar inicialmente una cantidad limitada o paginada.

Ordenar por:

1. `fecha_inicio DESC`;
2. `codigo_contrato DESC`.

---

# 15. Domicilio del inmueble

La tabla `inmuebles` tiene:

```text
codigo_inmueble
domicilio_calle
domicilio_nro
domicilio_edificio
domicilio_piso
domicilio_dpto
pais
provincia
departamento
localidad
barrio
cp
cod_tipo_inmueble
```

Muchos registros antiguos tienen todo el domicilio dentro de:

```text
domicilio_calle
```

y los demás componentes vacíos.

Crear un método o atributo calculado que arme el domicilio sin duplicar partes y sin asumir que los campos están normalizados.

Orden sugerido:

```text
domicilio_calle
domicilio_nro
domicilio_edificio
domicilio_piso
domicilio_dpto
localidad
```

Omitir partes vacías.

---

# 16. Tipos de inmuebles

Tabla:

```text
tipos_de_inmuebles
```

Relación:

```text
inmuebles.cod_tipo_inmueble
    -> tipos_de_inmuebles.cod_tipo_inmueble
```

Valores actuales:

```text
1 Casa
2 Departamento
3 Cochera
4 Local
5 Depósito
6 Terreno
```

No hardcodear estos valores en la vista. Leerlos desde la tabla.

---

# 17. Provincias, localidades y países

Tablas existentes:

```text
provincias
localidades
paises
```

## `provincias`

Campos:

```text
nombre
pais
codprov
```

Clave primaria:

```text
nombre
```

## `localidades`

Campos:

```text
codpais
pais
provincia
caractel
nombre
cp
```

No tiene una clave primaria declarada.

## `paises`

Campos:

```text
cod_pais
nombre
```

## Comportamiento esperado

* cargar provincias desde la base;
* cargar localidades según la provincia;
* al elegir localidad, completar:

  * `caractel`;
  * `cp`;
* permitir corregir manualmente característica y código postal después del autocompletado;
* usar Argentina, Santa Fe y Santa Fe como valores predeterminados en alta;
* no asumir integridad referencial que la base no tenga;
* aplicar `TRIM()` a los valores.

La carga dependiente de localidades puede resolverse con un endpoint JSON pequeño y JavaScript nativo.

No incorporar frameworks frontend.

---

# 18. Rutas y controladores

Crear rutas REST coherentes con el proyecto.

Ejemplo conceptual, ajustar a las convenciones existentes:

```php
Route::resource('clientes', ClienteController::class)
    ->only(['index', 'show', 'create', 'store', 'edit', 'update']);
```

Agregar una ruta para localidades por provincia si resulta necesaria.

No implementar `destroy`.

Podés usar un único `index` con el cliente seleccionado o separar `index` y `show`, según encaje mejor con el diseño actual.

Priorizar URLs navegables y recargables, sin depender de estado exclusivamente en JavaScript.

---

# 19. Arquitectura esperada

Separar responsabilidades.

Como mínimo evaluar:

```text
app/Models/Cliente.php
app/Models/Contrato.php
app/Models/Inmueble.php
app/Models/TipoDeInmueble.php
app/Http/Controllers/ClienteController.php
app/Http/Requests/StoreClienteRequest.php
app/Http/Requests/UpdateClienteRequest.php
app/Rules/... o app/Services/...
resources/views/clientes/index.blade.php
resources/views/clientes/create.blade.php
resources/views/clientes/edit.blade.php
resources/views/clientes/partials/...
```

No crear clases innecesarias, pero evitar controladores monolíticos.

La regla de duplicidad debe quedar aislada y reutilizable.

---

# 20. Rendimiento

Evitar:

* N+1;
* cargar todos los clientes;
* cargar todos los contratos;
* consultas repetidas por cada inmueble;
* `SELECT *` innecesarios;
* búsquedas con PHP sobre colecciones completas.

Usar:

* paginación SQL;
* eager loading controlado;
* `select()` con columnas necesarias;
* `withCount()` o `exists()` cuando corresponda;
* índices existentes.

Índices relevantes existentes:

```text
clientes_index:
codigo_cliente, cuit, doctipo, docnro, id_prop, id_inq

contratos_index:
codigo_contrato, fecha_contrato, numero_de_contrato

inmuebles_index:
codigo_inmueble, domicilio_calle, domicilio_nro, pais, provincia, localidad, barrio
```

La búsqueda por nombres puede no estar completamente cubierta por índices. Implementarla correctamente primero, sin modificar la base.

---

# 21. Pruebas

Crear pruebas Feature y, cuando corresponda, Unit.

Cubrir al menos:

1. listado de clientes autenticado;
2. búsqueda por apellido;
3. búsqueda por razón social;
4. búsqueda por documento;
5. filtro de propietarios;
6. filtro de inquilinos;
7. alta de persona física válida;
8. alta de persona jurídica válida;
9. rechazo de persona física sin apellido;
10. rechazo de persona jurídica sin razón social;
11. documento permitido con mismo número y distinto tipo;
12. rechazo de la misma combinación `doctipo + docnro`;
13. modificación excluyendo al propio cliente;
14. preservación de columnas ajenas al formulario;
15. consulta de contratos vinculados;
16. fallback cuando el número de contrato es cero;
17. construcción de domicilio con datos heredados;
18. acceso no autorizado según middleware existente.

No usar `RefreshDatabase` contra una base heredada real si eso puede destruir información.

Revisar cómo está configurado el entorno de pruebas antes de ejecutarlas.

---

# 22. Metodología de trabajo

Trabajá por etapas.

## Etapa 1: inspección

Antes de modificar:

1. revisar estructura actual del proyecto;
2. revisar rutas;
3. revisar layout principal;
4. revisar autenticación;
5. revisar menú lateral o navegación;
6. revisar módulos ya implementados;
7. revisar conexión a PostgreSQL;
8. verificar si ya existen modelos o controladores relacionados;
9. verificar convenciones de nombres y estilos;
10. revisar si existe código parcial del módulo Clientes.

Informar brevemente qué encontraste.

## Etapa 2: propuesta

Antes de escribir una gran cantidad de código, presentar:

* archivos a crear;
* archivos a modificar;
* estructura de rutas;
* enfoque de modelos;
* enfoque de validación;
* enfoque de interfaz.

## Etapa 3: implementación

Implementar en cambios pequeños y verificables.

## Etapa 4: verificación

Ejecutar:

```bash
gei-artisan route:list
gei-artisan test
```

Si existe formateador configurado:

```bash
gei-artisan pint
```

Para frontend, cuando corresponda:

```bash
docker exec -it gei-node npm run build
```

No ejecutar `npm install` salvo que realmente falten dependencias.

---

# 23. Resultado final esperado

Al finalizar, informar:

1. archivos creados;
2. archivos modificados;
3. rutas agregadas;
4. modelos y relaciones implementados;
5. validaciones implementadas;
6. consultas usadas para búsqueda y contratos;
7. pruebas ejecutadas;
8. resultado de las pruebas;
9. resultado del build;
10. decisiones técnicas relevantes;
11. limitaciones o datos heredados detectados;
12. pasos manuales necesarios, si los hubiera.

No afirmar que algo funciona si no fue ejecutado o verificado.

No cambiar partes del proyecto ajenas al módulo Clientes.

Comenzá inspeccionando el proyecto. No implementes todavía hasta mostrar el diagnóstico inicial y el plan de archivos.
