# Motor óptico y estudio de diseño

La integración usa `@ybouane/liquidglass` 1.0.3 y su API publicada: `LiquidGlass.init({ root, glassElements })`. `selector` no es una opción de esta versión. Referencias: [repositorio](https://github.com/ybouane/liquidglass) y [demostración](https://liquid-glass.ybouane.com/).

## Uso del estudio

Accede con una cuenta con rol `superadmin` y abre **Estudio Liquid Glass** en el menú. Selecciona Paneles, Menú o Botones; ajusta sus parámetros; alterna el fondo de prueba; publica para compartir el diseño con las siguientes solicitudes del sistema y del login. Descartar recupera el último perfil guardado. Restablecer recupera el perfil inicial y requiere publicar para persistirlo.

El estudio ofrece los 19 parámetros de `GlassConfig`: 16 controles numéricos, forma del bisel, reacción de botón y arrastre de prueba. El arrastre se limita a la vista previa para conservar la geometría del menú y los formularios. El servidor valida las claves, tipos y rangos, exige el rol Superadmin además del permiso de la ruta y registra la publicación en auditoría. No se guardan ajustes al mover un deslizador.

## Arquitectura

- `GlassScene` crea una raíz y un canvas ambiental hermano de las superficies. El fondo usa base blanca o grafito cálido y tres luces suaves derivadas del branding. El shader lee sus píxeles directamente, sin rasterizar un fondo HTML. `MacroGlass` usa la fábrica de perfiles compartida; menú, cabecera y guía son hijos directos de la escena.
- `SceneButton` conserva un ancla en el formulario y monta mediante portal el botón real como hijo directo de la escena. El atributo `form` mantiene el envío y la semántica accesible. El motor espera su montaje antes de registrar las superficies.
- `GlassEngineService` espera las fuentes, configura CORS de las imágenes, serializa las inicializaciones y destruye los recursos y el contexto WebGL al desmontar. Existe una instancia por escena; el estudio añade una segunda para la vista previa.
- No hay `data-dynamic` en formularios ni en el espacio de trabajo. Las actualizaciones de configuración cambian `data-config`; no recrean el contexto. El canvas se redibuja al cambiar tema o branding y `markChanged` actualiza el sombreado sin volver a rasterizar los formularios. El final de las transiciones también solicita solamente el nuevo sombreado. El motor conserva una invalidación explícita para los otros contenidos de la vista previa que sí requieren capturar HTML.
- Los controles anidados, desplegables, calendarios, diálogos, ayudas y SweetAlert2 usan la capa óptica CSS compartida. Conservan etiquetas, foco, teclado y semántica HTML accesible. Los desplegables y calendarios visuales son componentes propios; los checkboxes visibles usan pistas de interruptor.
- A partir de 768 px, el menú reserva espacio y desplaza el contenido. Por debajo de 768 px, ocupa la ventana con 12 px de margen y oculta e inhabilita el contenido. Su capa de lectura protege el contraste.
- Los parámetros del panel sincronizan las variables de los microcontroles; el perfil de botón sincroniza su borde, desenfoque y sombra. Los parámetros exclusivos del shader se ven en los macrocristales y en la vista previa.

## Errores

El contrato incluye `success`, `error`, `errorCode`, `technicalMessage` y `fullError`. Las respuestas JSON e Inertia fallidas usan el contrato común. La validación Inertia conserva su redirección y el interceptor la transforma al mismo diagnóstico visual. Las visitas directas fallidas disponen de una página de cristal que carga el diagnóstico con SweetAlert2, incluso sin consultar la base de datos.

El modal incluye mensaje principal, código, explicación técnica, detalle desplegable y copia del diagnóstico. La altura del contenido se limita para mantener visible la confirmación. Las trazas solo se incluyen con `APP_DEBUG=true`; en producción permanecen endpoint, método, fecha, estado e identificador de solicitud, sin contraseñas, tokens ni cookies.

## Instalación y límites medidos

`npm ci --include=dev` seguido de `npm run build` compila los recursos. La publicación npm de la biblioteca ejecuta `patch-package` sin incluirlo en sus dependencias de ejecución; el proyecto lo incorpora para que su instalación habitual y `npm rebuild @ybouane/liquidglass --offline` funcionen.

La prueba en Chrome registró 60 FPS del bucle en reposo con la escena y la vista previa activas. Las capturas puntuales, cambios de tamaño, inspección, ocultación de la ventana y capacidad de la GPU afectan esa cifra. No se promete un mínimo universal de FPS. La capa híbrida evita capturar los formularios por fotograma y conserva respuesta inmediata de los controles.

## Verificación

Se ejecutaron `npm run build` y diez pruebas con 103 comprobaciones, que cubren autenticación, acceso del administrador a 23 rutas, autorización del estudio, validación de parámetros, persistencia, auditoría, disponibilidad de los perfiles en el login y errores JSON/visitas directas.

También pasaron dos pruebas de JavaScript con `node --test tests/Frontend/errorDiagnostic.test.mjs`, que comprueban la redacción de credenciales dentro de cuerpos JSON de Axios, cabeceras y estructuras circulares. Los cuerpos de solicitud no interpretables como JSON se omiten del diagnóstico.

La revisión del navegador incluyó inicio de sesión real de `ian`, dos canvases del login registrados en la misma raíz, ambos temas, publicación y recarga, restauración a refracción 0.69, cambio de opciones de reporte, calendario propio, menú a 800 px, menú móvil a 390 × 844 y diagnóstico de credenciales inválidas con sus cuatro bloques.

## Correcciones de legibilidad y branding

- El selector de tema guarda mediante Axios y la cookie XSRF actual. Se eliminó el encabezado que reutilizaba el token de la página inicial después del login.
- Se retiró la lente adicional del espacio de trabajo y el fondo duplicado de las guías internas. Cabeceras y guías conservan el fondo transparente; los controles internos usan una capa ligera y redondeada.
- La configuración de branding permite ver el fondo con los colores editados antes de guardar. Al salir sin cambios o cancelar la edición se recupera la paleta guardada. Los parámetros ópticos publicados por el administrador se conservan.
- Los interruptores de configuración y usuarios comparten `LiquidToggle`, con pista coloreada y texto Activado/Desactivado.
- La paginación identifica los resultados de cada página y utiliza enlaces Página anterior, Página siguiente o Volver a la primera página. No muestra Inicio/Fin aislados ni un pie vacío cuando no hay otra página.
- Las plantillas presentan los campos automáticos mediante nombres comprensibles e inserción por botones. La conversión a identificadores internos ocurre al editar; la vista previa muestra datos ficticios y el formato guardado sigue siendo compatible con el servicio de envío.

Para estas correcciones pasaron trece pruebas PHP (99 comprobaciones) y cuatro pruebas JavaScript, además de la compilación. La revisión del navegador confirmó cambio de tema tras el login sin 419, persistencia al navegar, actualización del canvas al cambiar el branding, fondos transparentes, interruptores coloreados e inserción de campos sin mostrar llaves técnicas.

## Fluidez sin reducir la calidad óptica

El parche reproducible `patches/@ybouane+liquidglass+1.0.3.patch` se aplica mediante `postinstall`; la versión queda fijada en 1.0.3. Añade un filtro de capturas, cancelación durante el arranque, detección de tamaños con `ResizeObserver` y presentación del primer frame completo antes de resolver `init`. Los archivos de shaders, valores ópticos y renderizador coinciden con el paquete original: no se reduce la resolución, la refracción ni el número de pasadas de desenfoque.

`glassCapturePolicy` comprueba el orden de pintura y las intersecciones con el margen óptico original de 20 px. Un formulario que no está debajo de otro cristal no necesita convertirse en una imagen. El login conserva la captura de la tarjeta bajo su botón y el estudio conserva el contenido bajo la lente. Las regiones ocultas y los enlaces accesibles recortados se excluyen hasta ser visibles. Los microcontroles mantienen sus estilos.

En reposo, el motor no consulta tamaños o posiciones en cada frame ni ejecuta el shader sin invalidaciones. El servicio agrupa las invalidaciones por frame y mantiene las solicitudes de captura separadas de las solicitudes de sombreado. Los portales del login agrupan las mediciones de desplazamiento y el contador del estudio actualiza su propio componente, sin renderizar el formulario cada segundo.

Budget entrega sus datos esenciales paginados en la primera respuesta. Así se inicializan correctamente las opciones del formulario y se evita presentar una lista vacía mientras se realiza una segunda solicitud. Se conserva el límite de 25 registros y la carga de relaciones, con el pequeño coste de esperar esas consultas antes de mostrar la nueva vista.

Los atributos `data-glass-startup-ms`, `data-glass-captured-snapshots` y `data-glass-skipped-snapshots` de la raíz permiten comprobar el arranque en el navegador. El tiempo corresponde al motor, no al tiempo total de red o navegación. El contador FPS refleja el bucle de animación y no es una medición de INP ni una garantía de FPS constantes.

Las pruebas de frontend incluyen conservación de las superposiciones, descarte de capturas innecesarias, aplicación del filtro al contenido mutado y ausencia de consultas DOM en reposo. La prueba de Budget exige que los tres grupos de datos estén presentes en la primera respuesta.

La verificación final pasó ocho pruebas JavaScript y catorce pruebas PHP (101 comprobaciones), además de la compilación. En Chrome, Budget registró 236 ms de inicialización del motor, cero capturas HTML y seis descartadas; el estudio registró 148 ms para la escena general y 421 ms para la vista previa, que conserva una captura necesaria. Son muestras locales del arranque del motor. Se comprobaron escritura en el buscador, navegación, expansión del menú, ambos temas y desplazamiento de Budget sin franjas visibles. La aplicación del parche sobre una copia limpia del paquete produjo archivos idénticos a los usados en la compilación.
