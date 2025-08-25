# StaticForge

**Convierte WP en HTML sólido**

StaticForge es un plugin de WordPress que convierte páginas dinámicas en archivos HTML estáticos sólidos, perfectos para sitios web ultra-rápidos y seguros.

## 🔥 Características

- **🔨 Forja HTML sólido** - Convierte páginas de WordPress en archivos HTML estáticos
- **☁️ Integración S3** - Sube automáticamente a Amazon S3 o guarda localmente
- **⚡ Auto-update** - Regenera automáticamente cuando actualizas páginas
- **🏠 Soporte para Home** - Incluye la página de inicio en la generación
- **📁 Estructura organizada** - Guarda como `slug/index.html`
- **🛡️ Fallback local** - Si S3 no está configurado, guarda en carpeta temporal

## 🚀 Instalación

1. Sube el plugin a `/wp-content/plugins/`
2. Activa el plugin desde el panel de WordPress
3. Ve a **StaticForge** en el menú del admin
4. ¡Comienza a forjar HTML sólido!

## ⚙️ Configuración

### Configuración S3 (Opcional)
1. Ve a **StaticForge > Configuración S3**
2. Introduce tus credenciales de AWS:
   - AWS Access Key
   - AWS Secret Key
   - Nombre del Bucket
   - Región de S3

### Sin S3
Si no configuras S3, los archivos se guardan en:
```
/wp-content/uploads/static-pages/
```

## 🛠️ Uso

1. **Generación Manual**:
   - Ve a **StaticForge**
   - Selecciona páginas y haz clic en "🔨 Forjar HTML"

2. **Auto-update**:
   - Marca el checkbox "Auto-update" para páginas específicas
   - Se regeneran automáticamente al actualizar la página

3. **Estructura de archivos**:
   ```
   static-pages/
   ├── home/index.html
   ├── sobre-nosotros/index.html
   └── contacto/index.html
   ```

## 📋 Requisitos

- WordPress 5.0+
- PHP 7.4+
- Permisos de escritura en `/wp-content/uploads/`

## 🔧 Desarrollo

Este plugin está en desarrollo activo. Contribuciones son bienvenidas.

## 📝 Changelog

### v1.0
- Generación inicial de páginas estáticas
- Integración con Amazon S3
- Sistema de auto-update
- Soporte para página home
- Estructura `slug/index.html`
- Fallback a carpeta temporal

## 👨‍💻 Autor

**Gerswin Pineda**

## 📄 Licencia

Este proyecto está bajo licencia GPL v2 o posterior.