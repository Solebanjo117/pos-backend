import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

export default withMermaid(
  defineConfig({
    title: 'HermeX POS',
    description: 'Documentación Completa del Sistema Punto de Venta HermeX',
    lang: 'es-ES',
    appearance: 'dark',

    head: [
      ['meta', { name: 'theme-color', content: '#3c3c3d' }],
      ['meta', { name: 'og:type', content: 'website' }],
      ['meta', { name: 'og:locale', content: 'es_ES' }],
    ],

    themeConfig: {
      logo: '/logo.svg',

      nav: [
        { text: 'Inicio', link: '/' },
        { text: 'Manual de Usuario', link: '/manual-usuario/' },
        { text: 'Manual Técnico', link: '/manual-tecnico/' },
        { text: 'Operaciones', link: '/operaciones/' },
        { text: 'Procesos de Negocio', link: '/procesos-negocio/' },
        {
          text: '📥 Descargas',
          items: [
            { text: '📄 Manual Usuario (PDF)', link: '/pdfs/HermeX-Manual-Usuario.pdf' },
            { text: '📄 Manual Técnico (PDF)', link: '/pdfs/HermeX-Manual-Tecnico.pdf' },
            { text: '📄 Operaciones (PDF)', link: '/pdfs/HermeX-Manual-Operaciones.pdf' },
            { text: '📄 Procesos (PDF)', link: '/pdfs/HermeX-Procesos-Negocio.pdf' },
          ]
        },
      ],

      sidebar: {
        '/manual-usuario/': [
          {
            text: 'Manual de Usuario',
            items: [
              { text: 'Introducción', link: '/manual-usuario/index' },
              { text: 'Instalación', link: '/manual-usuario/instalacion' },
              {
                text: 'Por Rol',
                collapsed: false,
                items: [
                  { text: 'Administrador', link: '/manual-usuario/roles/administrador' },
                  { text: 'Gerente', link: '/manual-usuario/roles/gerente' },
                  { text: 'Vendedor', link: '/manual-usuario/roles/vendedor' },
                  { text: 'Cajero', link: '/manual-usuario/roles/cajero' },
                  { text: 'Comprador', link: '/manual-usuario/roles/comprador' },
                ]
              },
              {
                text: 'Por Módulo',
                collapsed: false,
                items: [
                  { text: 'Punto de Venta (POS)', link: '/manual-usuario/modulos/pos' },
                  { text: 'Inventario', link: '/manual-usuario/modulos/inventario' },
                  { text: 'Compras', link: '/manual-usuario/modulos/compras' },
                  { text: 'Cotizaciones', link: '/manual-usuario/modulos/cotizaciones' },
                  { text: 'Clientes', link: '/manual-usuario/modulos/clientes' },
                  { text: 'Vehículos', link: '/manual-usuario/modulos/vehiculos' },
                  { text: 'Reportes', link: '/manual-usuario/modulos/reportes' },
                  { text: 'Órdenes de Trabajo', link: '/manual-usuario/modulos/ordenes-trabajo' },
                ]
              },
              {
                text: 'Flujos de Trabajo',
                collapsed: false,
                items: [
                  { text: 'Venta de Mostrador', link: '/manual-usuario/flujos/venta-mostrador' },
                  { text: 'Cotización a Venta', link: '/manual-usuario/flujos/cotizacion-a-venta' },
                  { text: 'Orden de Compra', link: '/manual-usuario/flujos/orden-compra' },
                  { text: 'Cierre de Caja', link: '/manual-usuario/flujos/cierre-caja' },
                  { text: 'Devoluciones', link: '/manual-usuario/flujos/devolucion' },
                ]
              },
              { text: 'Solución de Problemas', link: '/manual-usuario/troubleshooting' },
            ]
          }
        ],

        '/manual-tecnico/': [
          {
            text: 'Manual Técnico',
            items: [
              { text: 'Introducción', link: '/manual-tecnico/index' },
              {
                text: 'Arquitectura',
                collapsed: false,
                items: [
                  { text: 'Visión General', link: '/manual-tecnico/arquitectura/overview' },
                  { text: 'Backend', link: '/manual-tecnico/arquitectura/backend' },
                  { text: 'Frontend', link: '/manual-tecnico/arquitectura/frontend' },
                  { text: 'Base de Datos', link: '/manual-tecnico/arquitectura/database' },
                  { text: 'Infraestructura', link: '/manual-tecnico/arquitectura/infraestructura' },
                ]
              },
              {
                text: 'API Documentation',
                collapsed: false,
                items: [
                  { text: 'Introducción', link: '/manual-tecnico/api/index' },
                  { text: 'Autenticación', link: '/manual-tecnico/api/authentication' },
                  { text: 'Endpoints', link: '/manual-tecnico/api/endpoints' },
                  { text: 'Paginación', link: '/manual-tecnico/api/pagination' },
                ]
              },
              {
                text: 'Base de Datos',
                collapsed: false,
                items: [
                  { text: 'Schema Overview', link: '/manual-tecnico/database/schema' },
                  { text: 'Diagramas ERD', link: '/manual-tecnico/database/erd' },
                  { text: 'Migraciones', link: '/manual-tecnico/database/migrations' },
                  { text: 'Seeders', link: '/manual-tecnico/database/seeders' },
                ]
              },
              {
                text: 'Frontend',
                collapsed: false,
                items: [
                  { text: 'Arquitectura', link: '/manual-tecnico/frontend/arquitectura' },
                  { text: 'Componentes', link: '/manual-tecnico/frontend/componentes' },
                  { text: 'Composables', link: '/manual-tecnico/frontend/composables' },
                  { text: 'Estado', link: '/manual-tecnico/frontend/estado' },
                  { text: 'Testing', link: '/manual-tecnico/frontend/testing' },
                ]
              },
              {
                text: 'Desarrollo',
                collapsed: false,
                items: [
                  { text: 'Setup', link: '/manual-tecnico/development/setup' },
                  { text: 'Patrones', link: '/manual-tecnico/development/patterns' },
                  { text: 'Testing', link: '/manual-tecnico/development/testing' },
                  { text: 'Debugging', link: '/manual-tecnico/development/debugging' },
                ]
              },
              {
                text: 'Seguridad',
                collapsed: false,
                items: [
                  { text: 'Autenticación', link: '/manual-tecnico/security/authentication' },
                  { text: 'Autorización', link: '/manual-tecnico/security/authorization' },
                  { text: 'Mejores Prácticas', link: '/manual-tecnico/security/best-practices' },
                ]
              },
            ]
          }
        ],

        '/operaciones/': [
          {
            text: 'Manual de Operaciones',
            items: [
              { text: 'Introducción', link: '/operaciones/index' },
              {
                text: 'Despliegue',
                collapsed: false,
                items: [
                  { text: 'Requisitos', link: '/operaciones/deployment/requisitos' },
                  { text: 'Instalación', link: '/operaciones/deployment/instalacion' },
                  { text: 'Configuración', link: '/operaciones/deployment/configuracion' },
                  { text: 'Actualizaciones', link: '/operaciones/deployment/actualizaciones' },
                ]
              },
              {
                text: 'Administración',
                collapsed: false,
                items: [
                  { text: 'Backup', link: '/operaciones/administracion/backup' },
                  { text: 'Recovery', link: '/operaciones/administracion/recovery' },
                  { text: 'Monitoring', link: '/operaciones/administracion/monitoring' },
                  { text: 'Logs', link: '/operaciones/administracion/logs' },
                ]
              },
              {
                text: 'Performance',
                collapsed: false,
                items: [
                  { text: 'Optimización', link: '/operaciones/performance/optimizacion' },
                  { text: 'Caching', link: '/operaciones/performance/caching' },
                  { text: 'Database Tuning', link: '/operaciones/performance/database-tuning' },
                ]
              },
              {
                text: 'Seguridad',
                collapsed: false,
                items: [
                  { text: 'SSL/TLS', link: '/operaciones/security/ssl-certificates' },
                  { text: 'Firewall', link: '/operaciones/security/firewall' },
                  { text: 'Actualizaciones', link: '/operaciones/security/updates' },
                ]
              },
            ]
          }
        ],

        '/procesos-negocio/': [
          {
            text: 'Procesos de Negocio',
            items: [
              { text: 'Introducción', link: '/procesos-negocio/index' },
              { text: 'Flujo de Ventas', link: '/procesos-negocio/flujo-ventas' },
              { text: 'Flujo de Inventario', link: '/procesos-negocio/flujo-inventario' },
              { text: 'Flujo de Compras', link: '/procesos-negocio/flujo-compras' },
              { text: 'Cuentas por Cobrar', link: '/procesos-negocio/flujo-cuentas-cobrar' },
              { text: 'Cuentas por Pagar', link: '/procesos-negocio/flujo-cuentas-pagar' },
            ]
          }
        ]
      },

      socialLinks: [
        { icon: 'github', link: 'https://github.com/hermexpos' }
      ],

      footer: {
        message: 'Documentación Oficial de HermeX POS',
        copyright: 'Copyright © 2024-2026 HermeX. Todos los derechos reservados.'
      },

      search: {
        provider: 'local',
        options: {
          locales: {
            es: {
              translations: {
                button: {
                  buttonText: 'Buscar',
                  buttonAriaLabel: 'Buscar documentación'
                },
                modal: {
                  noResultsText: 'No se encontraron resultados',
                  resetButtonTitle: 'Limpiar búsqueda',
                  footer: {
                    selectText: 'para seleccionar',
                    navigateText: 'para navegar',
                    closeText: 'para cerrar'
                  }
                }
              }
            }
          }
        }
      }
    }
  })
)
