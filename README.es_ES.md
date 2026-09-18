# Ticket Report & Sign — plugin para GLPI 10-12 (clave: `glpiticketreportsign`)

*[Read this in English](README.md)*

Agrega una pestaña **Report** (Informe) a cada Ticket. Desde esa pestaña, el
técnico asignado (o uno de los responsables de su grupo) puede:

1. **Generar un informe en PDF** con la cabecera del ticket, todos los
   seguimientos y todas las soluciones, con sus imágenes adjuntas
   incrustadas.
2. **Firmar el informe** dibujando una firma en cualquier dispositivo — la
   misma pestaña del navegador soporta ratón en escritorio y táctil en
   móvil, sin necesidad de código QR.
3. **Enviar el informe por correo** a un destinatario (p. ej. el cliente
   desde otro dispositivo); el correo contiene un enlace de un solo uso y
   con vencimiento a una página de firma que funciona sin iniciar sesión
   en GLPI.
4. El PDF firmado se guarda como un **Documento** normal de GLPI, vinculado
   al ticket, así que también aparece en la pestaña **Documentos** del
   ticket.

## Requisitos

* GLPI 10.0 – 12.0
* PHP 8.2+
* Extensiones de PHP: `gd` (usada para renderizar la firma dentro del PDF)
* FPDF — **incluido** en `pdf/` (la distribución completa de upstream,
  incluyendo la carpeta `font/`, viene con el plugin; no hay que instalar
  nada aparte).
* Librerías JS de terceros (ver `public/vendor/.gitkeep`):
  * pdf.js 4.x
  * signature_pad 5.x

## Instalación

```sh
cd plugins/
git clone <este-repo> glpiticketreportsign
# Copia los archivos JS de terceros en public/vendor/ — ver el .gitkeep
# de esa carpeta para los nombres exactos.
```

En **Configurar → Plugins**, instala y activa **Ticket Report & Sign**.

## Autorización

Solo los usuarios que cumplan alguna de estas condiciones en un ticket
dado pueden ver la pestaña Report y actuar sobre sus informes:

* Técnico asignado directamente (`glpi_tickets_users.type = ASSIGN`)
* Un usuario con `glpi_groups_users.is_manager = 1` en **a)** cualquier
  grupo asignado al ticket, o **b)** cualquier grupo que contenga a uno
  de los técnicos asignados.

El derecho `plugin_glpiticketreportsign_report` (LECTURA / CREAR /
ACTUALIZAR) controla si la pestaña es visible siquiera; la regla por
ticket se aplica en `Security\Authorizer`.

## Limitaciones / puntos a tener en cuenta

* **Unicode en los PDF.** Las fuentes base de FPDF solo soportan CP1252,
  así que los caracteres no latinos se transliteran con `iconv`. Cambia
  FPDF por tFPDF (o agrega una fuente TTF Unicode) si necesitas cirílico,
  griego, CJK, etc.
* **Formatos de imagen.** Solo los adjuntos PNG/JPEG/GIF se incrustan en
  el documento. Los demás formatos solo se listan por nombre.
* **Envío de correos.** Pasa por la configuración estándar de SMTP /
  mailer de GLPI — asegúrate de tenerla configurada en **Configurar →
  Notificaciones → Configuración de los seguimientos por correo**.

## Estructura de carpetas

```
glpiticketreportsign/
├── setup.php / hook.php / composer.json / glpiticketreportsign.xml
├── ajax/                           # generate / sign_submit / send_sign_email / pdf_bytes
├── front/                          # download.php (autenticado) / sign.php (por token)
├── install/                        # (reservado)
├── locales/                        # en_GB.po / es_ES.po
├── pdf/                            # distribución vendored de FPDF
├── public/
│   ├── css/ticketreport.css        # nombre de archivo sin cambiar; solo cambió la key del plugin
│   ├── js/ticketreport.js          # residuo sin uso de un diseño anterior (ver nota abajo)
│   ├── js/sign-page.js             # página pública de firma
│   └── vendor/                     # copiar aquí pdf.js + signature_pad
└── src/
    ├── Install/Installer.php
    ├── Integration/TicketTabBase.php    # lógica de la pestaña (compartida)
    ├── Integration/TicketTab.php        # hoja: GLPI <12 ($rightname sin tipo)
    ├── Integration/TicketTab.glpi12.php # hoja: GLPI 12+ ($rightname tipado `string`)
    ├── Mail/Mailer.php
    ├── Pdf/ReportPdf.php           # renderizador FPDF
    ├── Pdf/ReportStorage.php       # vinculación con Document + Document_Item
    ├── Profile.php                 # permisos
    ├── Report/ReportRecord.php     # lógica de CommonDBTM (compartida)
    ├── Report.php                  # hoja: GLPI <12 ($rightname sin tipo)
    ├── Report.glpi12.php           # hoja: GLPI 12+ ($rightname tipado `string`)
    └── Security/
        ├── Authorizer.php          # regla por ticket "técnico asignado / responsable"
        └── SigningToken.php        # tokens de un solo uso firmados con HMAC, por correo
```
