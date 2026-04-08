# CLAUDE.md - Arquitectura HermeX POS (Frontend + Backend)

## 📋 Proyectos

- **Backend**: `~/herd/pos-backend` (Laravel)
- **Frontend**: `~/herd/pos-frontend`

## 🔄 Comunicación entre proyectos

### URLs Base
- **Backend API**: `http://localhost:8000/api` (desarrollo)
- **Frontend**: `http://localhost:3000` (desarrollo)

### Estructura de Requests
Todas las llamadas desde frontend a backend siguen este patrón:

```
GET|POST|PUT|DELETE /api/endpoint
Content-Type: application/json
Authorization: Bearer {token}

{
  // payload
}
```

### Response estándar
```json
{
  "success": true,
  "data": { ... },
  "message": "opcional"
}
```

## 📡 Flujos principales

### Autenticación
1. Frontend POST `/api/auth/login` → obtiene token JWT
2. Frontend guarda token en localStorage
3. Frontend incluye `Authorization: Bearer {token}` en todas las requests

### Sync de datos
- Backend usa timestamps (`updated_at`) para cambios
- Frontend cache local con invalidación automática

## 🛠️ Cómo trabajar en ambos

### Mientras desarrollas
1. Inicia backend: `cd ~/herd/pos-backend && php artisan serve`
2. Inicia frontend: `cd ~/herd/pos-frontend && npm start`
3. Frontend hace calls a `http://localhost:8000/api`

### Testing
- Backend: `php artisan test`
- Frontend: `npm test`

## 📝 Convenciones compartidas

- **Backend responses**: snake_case en los datos
- **Frontend**: camelCase en variables/funciones
- **Timestamps**: ISO 8601 (YYYY-MM-DDTHH:mm:ss)
- **IDs**: numéricos o UUID
- **Errores**: frontend valida antes de enviar, backend valida siempre

## 🔑 Variables de entorno

### Backend (.env)
```
APP_URL=http://localhost:8000
```

### Frontend (.env)
```
REACT_APP_API_URL=http://localhost:8000/api
```

## 📚 Documentación adicional

- `AGENT_CONTEXT.md` - Contexto del proyecto
- `DOCUMENTATION_IMPLEMENTATION_PLAN.md` - Plan de implementación
