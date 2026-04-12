# ⚙️ Manual de Operaciones - HermeX POS

Documentación para personal de operaciones, DevOps e ingenieros de infraestructura.

Este manual cubre:
- ✅ Instalación y despliegue en producción
- ✅ Configuración de servidores
- ✅ Backup y recuperación ante desastres
- ✅ Monitoreo y logs
- ✅ Optimización de performance
- ✅ Seguridad operativa

---

## 🚀 Despliegue

### [Requisitos de Servidor](./deployment/requisitos)
Especificaciones mínimas y recomendadas:
- **OS:** Ubuntu 22.04 LTS
- **PHP:** 8.2 (con extensiones)
- **PostgreSQL:** 15
- **Redis:** 7
- **Node.js:** 18+
- **Memory:** 4GB mínimo, 8GB recomendado
- **Disk:** 50GB mínimo

### [Guía de Instalación](./deployment/instalacion)
Pasos completos para instalar HermeX POS en un servidor nuevo:
1. Preparar servidor
2. Instalar dependencias
3. Clonar repositorio
4. Instalar dependencias PHP y Node
5. Configurar base de datos
6. Ejecutar migraciones
7. Configurar web server (nginx)
8. Configurar SSL/TLS

### [Configuración](./deployment/configuracion)
Configuración de:
- Variables de entorno (.env)
- nginx como reverse proxy
- Supervisor para job workers
- Cron jobs para scheduled tasks
- Redis para cache y queue

### [Actualizaciones](./deployment/actualizaciones)
Procedimiento para actualizar a nuevas versiones:
1. Backup de datos
2. Descargar código nuevo
3. Instalar dependencias
4. Ejecutar migraciones
5. Clear cache
6. Reiniciar servicios

---

## 💾 Administración

### [Backup](./administracion/backup)
Estrategia de respaldos:
- **Base de datos:** Diario (pg_dump)
- **Archivos:** Diario (rsync)
- **Retención:** 30 días locales, 90 días en almacenamiento externo
- **Procedimiento:** Automatizado con cron

Script de backup incluido para:
```bash
backup.sh
├── PostgreSQL database
├── Archivos de aplicación
└── Archivos subidos (storage/)
```

### [Recuperación ante Desastres](./administracion/recovery)
Procedimientos de recuperación:
- Restaurar desde backup
- Recuperar base de datos
- Validar integridad de datos
- Pruebas de failover

### [Monitoreo](./administracion/monitoring)
Qué monitorear:
- CPU y memoria
- Espacio en disco
- Base de datos (queries lentas, connections)
- Redis (memory, evictions)
- Errores de aplicación

Herramientas recomendadas:
- New Relic o Datadog
- ELK Stack (Elasticsearch, Logstash, Kibana)
- Prometheus + Grafana

### [Logs](./administracion/logs)
Gestión de logs:
- **Ubicación:** `/storage/logs/`
- **Rotación:** Daily (configurable)
- **Retención:** 14 días
- **Niveles:** debug, info, notice, warning, error, critical

Acceso a logs:
```bash
# Ver logs en tiempo real
tail -f storage/logs/laravel.log

# Ver solo errores
grep "ERROR" storage/logs/laravel.log
```

---

## 📊 Performance

### [Optimización General](./performance/optimizacion)
Guía de optimización:
- Indexación de base de datos
- Lazy loading de relaciones
- Paginación de resultados
- Compresión de responses

### [Caching](./performance/caching)
Estrategia de cache con Redis:
- Query caching
- View caching
- Application caching
- Session caching

Comandos útiles:
```bash
php artisan cache:clear
php artisan route:cache
php artisan config:cache
```

### [Database Tuning](./performance/database-tuning)
Optimización de PostgreSQL:
- Configuración de postgresql.conf
- Índices apropiados
- Vacío de tablas
- Análisis de query plans

---

## 🔒 Seguridad

### [SSL/TLS Certificates](./security/ssl-certificates)
Instalación de certificados SSL/TLS:
- Let's Encrypt (gratuito)
- Auto-renovación con Certbot
- Configuración en nginx

### [Firewall](./security/firewall)
Configuración de firewall (ufw):
- Puertos permitidos (80, 443, 22)
- Bloqueo de puertos no necesarios
- Rate limiting

### [Actualizaciones de Seguridad](./security/updates)
Procedimiento de actualización de seguridad:
- Monitorear CVEs
- Aplicar parches
- Reiniciar servicios mínimamente disruptivos
- Testing en staging antes de producción

---

## 🎯 Tareas Comunes

### Reiniciar servicios
```bash
# Reiniciar PHP-FPM
sudo systemctl restart php8.2-fpm

# Reiniciar nginx
sudo systemctl restart nginx

# Reiniciar Redis
sudo systemctl restart redis-server

# Reiniciar Queue workers
sudo supervisorctl restart hermex-worker:*
```

### Limpiar cache
```bash
php artisan cache:clear
php artisan route:cache
php artisan config:cache
```

### Ver estado de jobs
```bash
php artisan queue:work --tries=3 --timeout=90
```

### Ejecutar migraciones
```bash
php artisan migrate --force
php artisan migrate:rollback
```

### Generar API docs
```bash
php artisan scribe:generate
```

---

## 📋 Checklist de Despliegue

### Pre-Despliegue (5 días antes)
- [ ] Notificar a usuarios de maintenance window
- [ ] Preparar rollback plan
- [ ] Actualizar documentación
- [ ] Revisar changelog

### Día de Despliegue
- [ ] Backup completo de base de datos
- [ ] Backup de archivos
- [ ] Deployar código nuevo
- [ ] Ejecutar migraciones
- [ ] Clear cache
- [ ] Verificar funcionalidad crítica
- [ ] Monitoreo aumentado por 2 horas

### Post-Despliegue
- [ ] Verificar no hay errores en logs
- [ ] Validar reports y datos
- [ ] Notificar fin del mantenimiento
- [ ] Documentar issues encontrados

---

## 🚨 Troubleshooting Operacional

### Base de datos llena
```bash
# Ver tamaño de database
sudo -u postgres psql -d hermex -c "SELECT pg_size_pretty(pg_database_size(current_database()));"

# Vacuum para recuperar espacio
sudo -u postgres psql -d hermex -c "VACUUM FULL;"
```

### Memory leak en Redis
```bash
# Monitorear memoria
redis-cli INFO memory

# Restart Redis si es necesario
sudo systemctl restart redis-server
```

### Errores frecuentes en logs
Revisar [LESSONS_LEARNED.md](../../LESSONS_LEARNED.md) para problemas comunes y sus soluciones.

---

## 📊 KPIs de Monitoreo

### Métricas de Disponibilidad
- Uptime: > 99.9%
- Response time: < 200ms (p95)
- Error rate: < 0.5%

### Métricas de Recursos
- CPU usage: < 80%
- Memory usage: < 85%
- Disk usage: < 80%
- Database connections: < 80% del máximo

---

## 🔗 Recursos Relacionados

- [Manual Técnico](/manual-tecnico/) - Arquitectura y desarrollo
- [Manual de Usuario](/manual-usuario/) - Funcionalidades
- [PATTERNS.md](../../PATTERNS.md) - Patrones del código
- [LESSONS_LEARNED.md](../../LESSONS_LEARNED.md) - Problemas conocidos

---

## 📞 Soporte Operacional

### Escalación de Incidentes
1. **Crítico:** Contactar a DevOps Lead inmediatamente
2. **Alto:** Notificar al equipo en 15 minutos
3. **Medio:** Crear ticket, asignar en el sprint
4. **Bajo:** Documentar para análisis

### Horario de Soporte
- **24/7:** Respuesta a incidentes críticos (downtime)
- **Business hours:** Soporte completo
- **Off-hours:** On-call rotation

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0  
**Versión de Sistema:** HermeX POS v1.1+
