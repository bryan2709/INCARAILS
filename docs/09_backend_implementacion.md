# Organización y funcionamiento del backend

Esta guía describe la implementación de la especificación `Documento_Maestro_Diseno_IncaRail_v2.11.docx`, especialmente las secciones 4, 5, 7, 8 y 10. Los diagramas 01–07 describen los componentes y reglas de negocio; esta guía identifica sus clases de implementación.

Para el recorrido operativo completo, consultar [Proceso técnico: instalación, consultas, importaciones y workers](10_proceso_tecnico.md). El [modelo entidad-relación actual](11_modelo_entidad_relacion.md) incorpora refrescos individuales, relaciones físicas verificadas y referencias lógicas de infraestructura.

La política de publicación se actualizó a petición del usuario: la ausencia, `null` o una lista vacía en `squad` permite publicar los equipos y los jugadores disponibles. Esta regla sustituye la restricción de completitud de P03 en la especificación v2.11. Se conservan las validaciones de estructura, identidades, temporada, duplicados y datos de jugadores.

Otra actualización solicitada permite refrescar expresamente una ficha de equipo o jugador. La importación de competiciones sigue sin consultas individuales recursivas. Los nuevos detalles usan las mismas tareas, cuotas, validaciones y reglas de reintento, y se guardan por separado de los snapshots.

## Responsabilidades

| Ubicación | Responsabilidad |
|---|---|
| `app/Http/Controllers` | Recibir solicitudes y devolver el contrato HTTP. |
| `app/Http/Requests` | Validar login, cambios administrativos, filtros y paginación. |
| `app/Http/Resources` | Serializar identidades de fútbol y el perfil de sesión. |
| `app/Exceptions/ApiExceptionRenderer.php` | Uniformar errores JSON y conservar cabeceras como `Retry-After`. |
| `app/Services/Football` | Consultar relaciones locales y resolver los detalles de competiciones, equipos y jugadores. |
| `app/Services/Administration` | Cambiar asignaciones y estados con protección de ACL. |
| `app/Services/AdminSafety.php` | Impedir escalamiento y conservar un administrador activo. |
| `app/Services/ImportCoordinator.php` | Crear/reutilizar importaciones y despachar etapas nuevas. |
| `app/Services/ImportPersistence.php` | Dirigir respuestas de catálogo, detalle y equipos. |
| `app/Services/Imports` | Validar el origen, mapear catálogo/temporadas y publicar snapshots. |
| `app/Services/Imports/EntityDetailPublisher.php` | Publicar atómicamente el detalle individual y su plantilla, conservando snapshots. |
| `app/Services/ExternalTasks` | Elegir workers, reservar cuota, registrar resultados y recuperar tareas. |
| `app/Services/FootballClient.php` | Ejecutar las únicas llamadas HTTP a football-data. |
| `app/Jobs/ProcessExternalTask.php` | Coordinar un intento: reservar, solicitar, registrar y continuar. |
| `app/Console/Commands` | Exponer sincronización y mantenimiento desde Artisan. |

## Lecturas y rendimiento

Los cinco GET obligatorios conservan sus rutas y los IDs externos del proveedor. Los detalles de equipos y jugadores cargan sus participaciones, snapshots, temporadas y competiciones mediante relaciones Eloquent anticipadas. El filtro de snapshots activos se resuelve con subconsultas SQL; no materializa todos los IDs en PHP ni consulta la competición y temporada por cada participación.

Los listados siguen mostrando entidades publicadas alguna vez; las participaciones pertenecen únicamente a snapshots activos. El catálogo permite `type=LEAGUE` o `type=CUP`, `search`, `page` y `per_page` (1–100). Los parámetros inválidos reciben 422. Los IDs de ruta deben ser enteros positivos.

El detalle de competición incluye `data.snapshot`, serializado por `CompetitionSnapshotResource`: ID de publicación, fecha, temporada publicada, totales y equipos con sus jugadores. `CompetitionDetails` carga las relaciones por adelantado para mantener constante el número de consultas al aumentar la plantilla. Los IDs de enlaces proceden de las identidades globales, mientras que nombres, escudos, posiciones y camisetas proceden del snapshot. Un refresh fallido o una temporada actual distinta conservan estos datos; la SPA etiqueta la temporada publicada y muestra tarjetas que abren directamente la ficha de cada equipo, donde se consulta su plantilla.

`PlayerResource` expone `first_name`, `last_name` y `source_updated_at` cuando se recibieron en la plantilla. `SnapshotPublisher` persiste esos campos opcionales, previa validación, usando UTC para la actualización del origen. Las participaciones del jugador incorporan escudo del equipo, emblema de competición y fecha final de temporada sin añadir consultas por participación.

`PlayerDetail.vue` presenta perfil, nacimiento/edad, posición y dorsal, equipos asociados y participaciones. El adaptador `playerProfile.js` acepta campos locales y camelCase del detalle individual del proveedor, incluidas las secciones opcionales de equipo actual y contrato. Una asociación en un snapshot no se interpreta como equipo actual; valores distintos de posición/dorsal se mantienen por participación. Contrato y estadio requieren que la API los entregue; la importación de competiciones obtiene las plantillas desde el listado de equipos y el refresco explícito del jugador obtiene su detalle individual. Si el detalle individual no suministra dorsal o posición, la ficha indica su ausencia y conserva los valores históricos únicamente dentro de sus participaciones.

`TeamProfileMapper` conserva una selección explícita de campos del club dentro de `snapshot_teams.metadata`: área, estadio, dirección, sitio web, colores, fundación, entrenador y competiciones declaradas. `GET /team/{id}` los devuelve en `participations[].profile`, junto con nacimiento y nacionalidad de las identidades de sus jugadores. `TeamResource` añade el área cargada anticipadamente y la actualización del origen. `TeamDetail.vue` permite seleccionar la participación/temporada y buscar o filtrar su plantilla, con posiciones en español, escudos y enlaces a las fichas.

Un readonly no dispara importaciones al consultar un catálogo vacío, una competición, un equipo o un jugador. Un usuario con `football.manage` puede inicializar el catálogo o iniciar la primera importación de una competición, equipo o jugador. Estas consultas responden 202 y proporcionan `/api/imports/{id}` para seguimiento.

Si el ID solicitado aún no existe localmente, `CompetitionDetails` crea una referencia con ese ID únicamente para un usuario con `football.manage`, y utiliza la misma cola y scope de importación. Accesos simultáneos o repetidos reutilizan el trabajo activo; una importación fallida no se reinicia en cada GET. Un readonly recibe 404 con un mensaje público sin nombres de modelos internos. Los detalles válidos completan nombre, código, tipo y emblema antes de publicar la temporada y equipos.

`TeamDetails` aplica la misma política a equipos sin publicación: crea o reutiliza la referencia y solicita una sola etapa `team_detail`. El GET incorpora `data.has_data` para distinguir datos publicados de una referencia pendiente; si la importación falla conserva su código y mensaje en `data.import`, sin reiniciarla en cada lectura. Un readonly recibe un 404 público sin crear referencias ni tareas. Una respuesta individual válida publica el perfil y los jugadores recibidos y marca `first_published_at`, por lo que el equipo aparece en el listado; una referencia fallida sigue excluida.

`PlayerDetails` aplica esa política a personas/jugadores en `/api/players/{id}` y su refresco. Con permiso de importación, crea o reutiliza una referencia no publicada y una sola etapa `player_detail` que consulta `/persons/{id}`. Devuelve `has_data` y el diagnóstico de importación; un GET posterior a un fallo no crea otra importación. Una respuesta válida publica el perfil individual, incluido `shirtNumber`, y marca `first_published_at`, sin consultar al equipo actual ni crear snapshots. Los usuarios readonly reciben un 404 público para identidades ausentes o pendientes, y esas referencias siguen fuera del listado hasta publicarse.

El catálogo lista competiciones recibidas del catálogo (`catalog_fetched_at`) o con un snapshot activo. Una referencia creada por acceso directo, todavía no publicada, no se ofrece como resultado del catálogo. `ensureCatalog` comprueba que existan entradas obtenidas del catálogo, por lo que esas referencias no impiden inicializarlo. El 503 por indisponibilidad sin snapshot conserva ahora el mensaje de la importación fallida.

En Docker, `php_vendor` contiene las dependencias compartidas por API, workers y scheduler. El código fuente continúa montado desde `backend/`. Esto evita leer miles de archivos de `vendor` desde el sistema de archivos de Windows en cada arranque o petición. Las instalaciones de Composer se ejecutan dentro del servicio `api`. OPcache está habilitado para el servidor PHP local y comprueba cambios del código.

Vite trabaja en desarrollo: los cambios del frontend no requieren `npm run build`. La consulta de importaciones espera a que termine una petición antes de programar la siguiente.

El servicio web define `VITE_USE_POLLING=true` para detectar cambios del volumen de Windows. Sin este sondeo, Vite puede conservar en memoria una versión anterior de un componente aunque el archivo y el build ya hayan cambiado.

## Importación y publicación

### Refresco individual y fechas

`POST /api/team/{id}/refresh` y `POST /api/players/{id}/refresh` requieren `football.manage`; ambas identidades pueden crearse por ID si aún no existen. Responden 202 con un `ImportResource`; los scopes `team:{id_interno}` y `player:{id_interno}` reutilizan la tarea activa bajo bloqueo de la entidad. Una importación contiene una sola etapa `team_detail` o `player_detail`, que solicita `/teams/{id_externo}` o `/persons/{id_externo}`. Las fichas publicadas se leen desde datos locales; el primer GET autorizado de un equipo o jugador sin publicación puede encolar su importación. Los GET no esperan una consulta HTTP externa y permiten seguir el trabajo pendiente mediante `data.import`.

La migración `2026_10_11_000001_add_entity_refresh_details` incorpora `source_profile` y `source_fetched_at` a equipos/jugadores, y sus referencias a importaciones. `EntityDetailPublisher` valida identidad, fechas, estructura y duplicados antes de escribir; registra los jugadores de la plantilla como identidades consultables y completa la importación en una transacción. Un resultado inválido conserva la última caché válida. La caché individual tiene prioridad sobre los campos globales que pueda actualizar posteriormente una importación de competición. No modifica `snapshot_teams`, `snapshot_players` ni `active_snapshot_id`.

`TeamResource` expone la plantilla actual en `data.team.squad`. `teamProfile.js` la incorpora como primera opción aunque existan participaciones con plantillas vacías; el usuario puede seguir seleccionando la plantilla publicada por competición/temporada. `PlayerResource` expone posición, `shirt_number` y equipo actual/contrato cuando se recibe el detalle individual. Un valor recibido de `shirtNumber` no reemplaza los dorsales propios de cada participación publicada.

La tabla «Participaciones publicadas» muestra equipo, competición, temporada y posición. La ficha muestra el dorsal en la cabecera del jugador; los valores de participación continúan guardados y disponibles en la API.

`last_updated_at` representa la última obtención individual o, cuando no existe, la fecha de publicación de las participaciones activas. La competición usa `snapshot.published_at`. `source_updated_at` conserva la fecha declarada por el proveedor. `UpdateInfo.vue` reúne en el bloque «Actualización y publicación» ambas fechas con hora de Lima, el refresco autorizado o recarga local, el estado de importación y los datos de snapshot. Los errores, esperas, avisos de disponibilidad parcial y reintentos de competición se muestran dentro del mismo panel. Un intento fallido mantiene su estado visible aunque exista un snapshot anterior publicado.

Los detalles de equipo y jugador incorporan `participations[].snapshot` con `id` y `published_at`, usando las relaciones ya cargadas y conservando únicamente versiones activas. `publicationSnapshots` agrupa esos datos con competición y temporada y elimina repeticiones por ID; cada versión se muestra una vez en el panel. La vista de competición usa su snapshot completo, incluidos los totales de equipos/jugadores. Las fechas del club y perfil que antes aparecían duplicadas se presentan ahora en el panel común.

`useFootballDetail.js` centraliza carga, cancelación al navegar y sondeo secuencial cada tres segundos; al terminar la importación vuelve a leer la ficha. En equipos y jugadores usa `has_data` y un callback de indisponibilidad para regresar al origen cuando no hay publicación y el trabajo falla, la respuesta está vacía o la API devuelve 403/404/503. Un fallo con datos publicados conserva la ficha y los fallos transitorios de red permiten reintentar sin navegar. Una sola consulta adicional por detalle obtiene la importación individual más reciente; los metadatos de snapshot no agregan consultas y el número sigue siendo constante al aumentar las participaciones.

### Importación de competición

1. `ImportCoordinator` reutiliza el scope activo bajo bloqueo de la competición. Una etapa ya creada no vuelve a despachar un Job en cada continuación.
2. `SourcePayloadValidator` comprueba identidad, temporada, cantidad de equipos, plantillas, unicidad de equipos/jugadores y campos opcionales. No se consulta recursivamente el detalle de equipos o personas.
   Las plantillas ausentes/null o vacías generan métricas en `quality_summary` y se publican como listas de jugadores vacías. Los equipos conservan su perfil y enlaces; no se copian jugadores de otros snapshots o competiciones. `InvalidSquadException` se reserva para estructuras malformadas y produce `SOURCE_SQUAD_INVALID`, con un mensaje público descriptivo. `CompetitionDetails` devuelve `error_message` y `quality_summary` también al recargar la competición; `CompetitionSnapshotResource` incluye las métricas de la versión publicada. Los errores de validación conservan el snapshot anterior.
3. Las fechas del origen se convierten a UTC y al formato de persistencia. Posición y camiseta ausentes siguen siendo `NULL`.
4. `SnapshotPublisher` bloquea competición e importación, comprueba temporada y scope y persiste identidades/participaciones por lotes.
5. El marcado `first_published_at`, el retiro del snapshot anterior, el cambio de puntero y el cierre de importación ocurren en una transacción. Un error revierte la publicación completa.
6. Un refresh fallido conserva el snapshot anterior. Los errores públicos mantienen un código de dominio y una explicación segura; las excepciones internas se registran para diagnóstico.

## Workers, cuota y recuperación

`TaskLeaseManager` reserva un intento y una unidad de cuota bajo el bloqueo del pool compartido. El máximo continúa siendo 10 solicitudes por 60 segundos en la configuración inicial, incluso con varios workers. La llamada HTTP se ejecuta después de cerrar esta transacción.

`TaskResultRecorder` comprueba `lease_version` antes de aceptar una respuesta. Una respuesta tardía no confirma una tarea cuya posesión cambió. Los errores de conexión, timeout, 429 y 5xx son transitorios; 403 es definitivo y 401 suspende la credencial. Los siguientes intentos deben usar un worker y una credencial distintos; con un solo worker la tarea queda en `waiting_worker`.

Al cerrar un fallo HTTP, `ProcessExternalTask` utiliza `FootballClient::errorMessage` para conservar el estado y el campo `message` del JSON del proveedor. Por ejemplo, un 403 con restricciones de suscripción produce `Solicitud externa fallida (HTTP 403). The resource you are looking for is restricted and apparently not within your permissions. Please check your subscription.` El código de dominio continúa siendo `SOURCE_ACCESS_DENIED`. El mensaje se guarda en `imports.error_message`, aparece en `GET /api/imports/{id}` y en `data.import.error_message` del detalle de equipo, jugador o competición, y se muestra en el panel común.

Se incorpora únicamente un `message` textual no vacío; sin él, con un tipo inválido o ante una respuesta no JSON, se conserva el diagnóstico HTTP genérico. El límite existente de 2.000 caracteres utiliza truncado Unicode. No se agregan otros campos del cuerpo, cabeceras ni excepciones internas al error público. En errores transitorios agotados se informa la respuesta del último intento. Las importaciones antiguas conservan el mensaje guardado entonces; un nuevo refresco usa este tratamiento.

Una respuesta 429 respeta `Retry-After` tanto numérico como fecha HTTP, con un cooldown conservador mínimo de 60 segundos. Esta cuota externa no se comunica como un 429 propio de la API.

`football:wake` recupera tareas pendientes antiguas y leases vencidos, continúa respuestas que quedaron sin persistir y cierra tareas cuyo tercer intento quedó incierto. La limpieza conserva identidades e historial y excluye cualquier snapshot apuntado por una competición. La retención de staging fallido se cuenta desde `imports.completed_at`; la de versiones retiradas, desde `retired_at`.

`FOOTBALL_SNAPSHOT_RETENTION_HOURS` configura esta retención y vale 24 por defecto.

## ACL y autenticación

Los permisos siguen siendo independientes y se leen de la base en cada solicitud. Asignar o reactivar un rol no permite conceder permisos que el actor no tiene. Las mutaciones preservan bajo transacción un usuario activo con los tres permisos de administración completa. Los códigos de sistema no pueden renombrarse ni desactivarse, incluyendo valores booleanos enviados como `0`.

Los tokens personales de Passport tienen una vigencia de 120 minutos. La SPA los recibe mediante una cookie de sesión cifrada `HttpOnly`, con `SameSite=Strict` y `Secure` según HTTPS/configuración; recupera el perfil mediante `/api/auth/me` al recargar. La cookie se adapta al Bearer dentro del servidor para conservar las validaciones de Passport y su revocación. Login y mutaciones autenticadas con cookie verifican CSRF; logout revoca el token e invalida la sesión. El [flujo de cookies](12_autenticacion_cookies.md) documenta contratos y configuración. Las rutas API abiertas sin sesión también devuelven 401 JSON, incluso sin `Accept: application/json`.

## Comandos de verificación

```bash
docker compose exec -T api composer format
docker compose exec -T api composer lint
docker compose exec -T api composer test -- --compact
docker compose exec -T api composer validate --no-check-publish
```

`format` aplica Laravel Pint; `lint` comprueba el mismo formato sin modificar archivos. Las pruebas utilizan SQLite en memoria, HTTP simulado y colas falsas. No consumen cuota del proveedor ni modifican MySQL de desarrollo.

## Navegación de regreso en las fichas

`useDetailNavigation.js` centraliza el destino y la etiqueta de «Volver». Las tarjetas de equipo en una competición incluyen su origen en el parámetro `from`; los enlaces de plantilla hacen lo mismo al abrir un jugador. El regreso del jugador conserva la URL del equipo con su origen, por lo que el recorrido competición → equipo → jugador puede recorrerse en sentido inverso. Un equipo abierto desde el listado vuelve a `/team`; un jugador abierto desde su listado vuelve a `/players`.

El origen se admite únicamente para las rutas locales de los listados y los detalles de competición, equipo y jugador. Si apunta a la misma ficha o no es válido, se descarta. El contexto viaja con la URL de la ficha; la autenticación sigue rigiéndose por la sesión de la SPA. Las tres fichas también pueden utilizar el destino anterior local guardado por Vue Router cuando no se recibe un origen explícito; sin un origen válido vuelven al listado correspondiente. Al enlazar una ficha que ya es el destino de regreso, se reutiliza esa URL para conservar la cadena sin anidar otro origen. Los enlaces al equipo actual y a los equipos publicados desde la ficha de jugador también conservan este contexto.

Cuando no hay snapshot y la importación termina en `failed`, `CompetitionDetail` detiene el sondeo y reemplaza la ruta por el origen. También lo hace ante una respuesta sin competición/importación activa o un error inicial 403, 404 o 503 sin datos publicados. `api.js` conserva estado HTTP, código y mensaje para decidir el regreso. Un fallo transitorio de red no provoca esta redirección y un refresco fallido con snapshot mantiene la ficha publicada.

`useImportRedirect` comparte el regreso y la creación del aviso entre competiciones, equipos y jugadores. `importNotice` guarda el código, mensaje, recurso e ID de importación junto al destino de regreso. `ImportNotice.vue`, dentro de `App.vue`, muestra el aviso en la página de origen, permite cerrarlo y lo limpia al salir de esa página. Los enlaces de competición en fichas y en `UpdateInfo` incluyen su origen. Los detalles anteriores de actualización/snapshot siguen mostrando el estado de su propia entidad.
