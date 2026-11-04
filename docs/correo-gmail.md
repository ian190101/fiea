# Correo con Gmail

## Configurar desde la interfaz

Abre **Configuración → Correo de Gmail** con una cuenta que tenga `settings.manage`. Introduce la cuenta, el nombre del remitente y una contraseña de aplicación de Google; pulsa **Guardar y comprobar conexión**. Se exige la confirmación reciente de la contraseña de tu cuenta FIEA.

La contraseña de aplicación se cifra en la base de datos, no se devuelve al navegador ni se incluye en la auditoría. Deja el campo vacío para conservarla; cambiar de cuenta requiere una contraseña nueva. Los workers recargan la configuración al procesar los siguientes correos, sin editar archivos ni reiniciar contenedores.

**Enviar correo de prueba** utiliza únicamente la cuenta emisora guardada y marca el mensaje como **PRUEBA**. El interruptor de envío de facturas controla los correos de invoices; la recuperación y verificación de cuentas son funciones independientes.

## Configuración inicial del servidor

La cuenta emisora de Ubuntu es `ianvera395@gmail.com`. Los destinatarios se registran en **Contactos**, se asignan al equipo o capítulo correspondiente y se seleccionan al preparar la factura. El selector admite búsqueda por nombre o correo, paginación y copias visibles u ocultas.

La configuración privada del servidor reside en `/home/ian/fiea-shared/app.env`. No se deben copiar contraseñas al repositorio ni a los informes.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_REQUIRE_TLS=true
MAIL_TIMEOUT=30
MAIL_USERNAME=ianvera395@gmail.com
MAIL_PASSWORD=<contraseña-de-aplicación-vigente>
MAIL_FROM_ADDRESS=ianvera395@gmail.com
MAIL_FROM_NAME=FIEA
FIEA_MAIL_PROVIDER=smtp
FIEA_EMAIL_QUEUE_ENABLED=true
FIEA_EMAIL_DISPATCH_WINDOW_SECONDS=86400
```

La contraseña de aplicación requiere verificación en dos pasos y puede generarse en la [cuenta de Google](https://myaccount.google.com/apppasswords). Consulta las [instrucciones oficiales](https://support.google.com/accounts/answer/185833?hl=es). Valida la autenticación antes de actualizar el entorno y recrea aplicación, worker y scheduler para que compartan la misma configuración.

Las facturas usan un PDF y una selección de destinatarios congelados. Un `Message-ID` estable permite rastrear el envío; Gmail SMTP no garantiza deduplicación por ese identificador. Por eso se guarda el estado `unknown` antes de iniciar SMTP y no se reenvía automáticamente un resultado incierto, incluso si se interrumpe el proceso. Comprueba esos casos en Gmail antes de preparar otro correo. Resend continúa disponible para instalaciones que necesiten idempotencia del proveedor.

Las notificaciones de recuperación de contraseña y verificación usan el mismo transporte SMTP. Un resultado `sent` confirma aceptación SMTP; la recepción en la bandeja debe comprobarse por separado.

## Validación

La suite ordinaria incluye el recorrido contacto → asignación → presupuesto → emisión y aprobación → PDF → preparación → outbox → cola → envío, para IC/MAT Initial/Final. También verifica recuperación y verificación de correo, selección por contacto y bloqueo de reenvíos ambiguos.

Por defecto se utiliza un transporte en memoria. Solo `FIEA_TEST_REAL_MAIL=1` activa seis mensajes reales a `ianvera395@gmail.com`; la prueba exige `APP_ENV=testing` y SQLite `:memory:` y no permite utilizar la base operativa. No ejecutes la suite con configuración de producción en caché.
