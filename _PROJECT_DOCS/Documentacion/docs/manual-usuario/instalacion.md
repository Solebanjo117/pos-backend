# 📱 Guía de Instalación - HermeX POS

Guía completa para instalar y configurar HermeX POS en tu dispositivo o servidor.

## 🖥️ Requisitos del Cliente

### Navegador Web
HermeX POS funciona en cualquier navegador moderno:

- ✅ **Google Chrome** 90+
- ✅ **Mozilla Firefox** 88+
- ✅ **Safari** 14+
- ✅ **Edge** 90+

### Hardware Mínimo
- **RAM:** 2 GB
- **Conexión a Internet:** Recomendada (requiere conexión con servidor)
- **Pantalla:** 1024x768 (recomendado 1366x768 o superior)

### Software Requerido
- Navegador web actualizado
- Nada más (es una aplicación web)

---

## 🔧 Configuración de Impresoras

### Impresora de Tickets (Térmica)

#### En Windows

1. **Agregar Impresora:**
   - Ir a Configuración → Dispositivos → Impresoras y escáneres
   - Hacer clic en "Agregar impresora"
   - Seleccionar tu impresora térmica
   - Instalar drivers si es necesario

2. **Configurar en HermeX:**
   - Ir a: Admin → Configuración → Impresoras
   - Seleccionar "Impresora Térmica"
   - Elegir la impresora de la lista
   - Probar con botón "Test"

#### En Mac

1. **Agregar Impresora:**
   - System Preferences → Printers & Scanners
   - Hacer clic en "+"
   - Seleccionar tu impresora térmica

2. **Configurar en HermeX:**
   - Similar a Windows (ver arriba)

#### En Linux

```bash
# Instalar CUPS (Common Unix Printing System)
sudo apt-get install cups

# Agregar impresora (acceder a http://localhost:631)
# Luego configurar en HermeX
```

### Impresora de Reportes (Inyección de Tinta / Láser)

Seguir el mismo proceso que impresora térmica. HermeX automáticamente detectará impresoras disponibles.

---

## 🔨 Hardware POS

### Lectores de Código de Barras

#### Lector USB Estándar

1. **Conectar:** Simplemente conectar al puerto USB
2. **Driver:** La mayoría son plug-and-play
3. **Configurar en HermeX:** No requiere configuración especial (funciona como teclado)

#### Lector Inalámbrico

1. **Emparejar:** Seguir instrucciones del fabricante
2. **Conectar:** El dispositivo aparecerá como teclado
3. **Usar:** Scanear código de barras (automáticamente inserta datos en campo activo)

### Cajón de Efectivo

#### Conexión USB

1. **Conectar:** Puerto USB dedicado
2. **Software:** Algunos requieren drivers (descargar de fabricante)
3. **Configurar en HermeX:**
   - Admin → Configuración → Hardware
   - Seleccionar "Cajón de Efectivo"
   - Elegir puerto COM/USB correcto

#### Conexión a Impresora Térmica

1. **Conectar:** Mediante cable a impresora térmica
2. **Configurar:** La impresora abre el cajón automáticamente después de imprimir

### Monitor de Cliente (Customer Display)

Opcional - muestra información al cliente durante transacción.

1. **Conectar:** Puerto USB o HDMI
2. **Configurar en HermeX:**
   - Admin → Configuración → Customer Display
   - Seleccionar dispositivo
   - Probar visualización

---

## 🌐 Configuración de Red

### Conexión Local (WiFi)

1. **Requisitos:**
   - Router WiFi en la tienda
   - Señal WiFi estable
   - Servidor de HermeX en la red local

2. **Pasos:**
   - Conectar dispositivo a WiFi
   - Obtener IP del servidor (ejemplo: 192.168.1.100)
   - Acceder a: `http://192.168.1.100:3000`

### Conexión Remota (Internet)

1. **Requisitos:**
   - Servidor desplegado en internet
   - URL del servidor (ejemplo: hermexpos.app)
   - Certificado SSL/TLS

2. **Acceso:**
   - Abrir navegador
   - Ir a: `https://hermexpos.app`

---

## 🔐 Acceso Inicial

### Credenciales por Defecto

**Usuario de Prueba:**
```
Email: admin@example.com
Password: password
```

⚠️ **IMPORTANTE:** Cambiar credenciales en producción inmediatamente

### Cambiar Contraseña

1. Acceder a tu perfil (esquina superior derecha)
2. Seleccionar "Mi Perfil"
3. Hacer clic en "Cambiar Contraseña"
4. Ingresar contraseña actual
5. Ingresar nueva contraseña
6. Confirmar

---

## 🎯 Configuración Inicial (First Setup)

### 1. Crear tu Empresa

1. Ir a: **Admin → Configuración → Información de la Empresa**
2. Completar campos:
   - Nombre de empresa
   - RUT/RFC
   - Dirección
   - Teléfono
   - Email de contacto

### 2. Crear Sucursales

1. Ir a: **Admin → Sucursales**
2. Hacer clic en "+ Nueva Sucursal"
3. Completar:
   - Nombre de sucursal
   - Ubicación/Dirección
   - Teléfono
   - Zona horaria
4. Guardar

### 3. Configurar Impuestos

1. Ir a: **Admin → Configuración → Impuestos**
2. Definir tasas de impuesto:
   - Estándar (IVA normal)
   - Reducido (si aplica)
   - Exento (si aplica)
3. Aplicar por defecto

### 4. Métodos de Pago

1. Ir a: **Admin → Configuración → Métodos de Pago**
2. Habilitar según necesidad:
   - ✅ Efectivo (siempre habilitado)
   - ✅ Tarjeta de Crédito/Débito
   - ✅ Transferencia Bancaria
   - ✅ Crédito de Tienda
3. Configurar comisiones si aplica

### 5. Usuarios

1. Ir a: **Admin → Usuarios**
2. Hacer clic en "+ Nuevo Usuario"
3. Completar:
   - Nombre
   - Email
   - Rol (Administrador, Gerente, Vendedor, Cajero, Comprador, Asistente)
   - Contraseña temporal
4. Usuario recibe email con credenciales

### 6. Respaldos

1. Ir a: **Admin → Respaldos**
2. Hacer clic en "Crear Respaldo"
3. Guardar archivo en lugar seguro
4. Configurar respaldos automáticos diarios

---

## ✅ Verificación Post-Instalación

### Checklist Inicial

- [ ] Browser se carga correctamente
- [ ] Puedes iniciar sesión
- [ ] Impresora detectada y probada
- [ ] Scanner código de barras funciona
- [ ] Información de empresa guardada
- [ ] Al menos una sucursal creada
- [ ] Impuestos configurados
- [ ] Métodos de pago habilitados
- [ ] Un usuario creado
- [ ] Primer respaldo completado

---

## 🆘 Solución de Problemas Comunes

### "No puedo acceder al servidor"

**Posibles causas:**
1. Servidor no está corriendo
2. URL/IP incorrecta
3. Firewall bloqueando conexión

**Solución:**
- Verificar que URL sea correcta
- Pedir a IT que verifique firewall
- Intentar desde otra red

### "Impresora no funciona"

**Causas comunes:**
1. No está seleccionada en HermeX
2. Cable desconectado
3. Driver no instalado
4. Papel terminado

**Solución:**
1. Verificar papel
2. Verificar conexión USB
3. En Admin → Impresoras, hacer clic en "Test"
4. Si sigue sin funcionar, reinstalar driver

### "Scanner no lee códigos de barras"

**Causas:**
1. Scanner no conectado
2. Código de barras dañado
3. Modo scanner incorrecto

**Solución:**
1. Verificar conexión USB
2. Probar con otro código de barras
3. Consultar manual del scanner

### "Datos lentos / Congelación"

**Causas:**
1. Conexión a internet lenta
2. Servidor sobrecargado
3. Cache del navegador lleno

**Solución:**
1. Verificar velocidad de internet
2. Limpiar cache del navegador: Ctrl+Shift+Delete
3. Reiniciar navegador
4. Contactar a IT

---

## 📞 Soporte Técnico

Si necesitas ayuda:

1. **Revisa:** [Solución de Problemas](/manual-usuario/troubleshooting)
2. **Contacta:** soporte@hermexpos.app
3. **Teléfono:** [Número de soporte]
4. **Horas:** Lunes-Viernes, 09:00-17:00

---

## 🔗 Recursos Relacionados

- [Manual del Administrador](./roles/administrador) - Para configuración avanzada
- [Manual del Cajero](./roles/cajero) - Para usar el POS
- [Solución de Problemas](./troubleshooting) - FAQ y problemas comunes

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
