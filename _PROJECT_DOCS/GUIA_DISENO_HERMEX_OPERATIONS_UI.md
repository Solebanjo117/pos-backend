# Guia de Diseno: HermeX Operations UI

## Proposito

Esta guia define el estilo visual y operativo base para las pantallas internas de HermeX. Debe usarse como referencia en modulos administrativos, inventario, compras, recepcion, productos, proveedores, caja, cotizaciones, reportes y cualquier flujo operativo donde el usuario toma decisiones con datos.

El objetivo no es hacer pantallas de marketing ni copiar un sistema externo. El objetivo es construir una interfaz de trabajo diaria: clara, densa, rapida de escanear y orientada a decisiones.

## Nombre Del Estilo

Nombre recomendado dentro del proyecto:

**HermeX Operations UI**

Descripcion generica:

**Operational Admin Workbench**

Es una mezcla de:

- Workbench operativo para inventario y compras.
- Dashboard compacto.
- ERP / inventory management moderno.
- B2B SaaS sobrio.
- Tablas densas con acciones claras.

## Principios Visuales

### 1. Operativo antes que decorativo

Las pantallas deben priorizar informacion accionable:

- stock;
- costo;
- precio;
- proveedor;
- estado;
- cantidades;
- alertas;
- referencias tecnicas;
- compatibilidad;
- historial o avance del flujo.

Evitar heroes, ilustraciones decorativas, textos explicativos largos y layouts de landing page.

### 2. Densidad controlada

La UI puede ser densa, pero debe estar organizada:

- metricas arriba;
- resumen visible;
- tablas compactas;
- secciones por tema;
- navegacion interna por proceso;
- acciones principales siempre faciles de encontrar.

La densidad no debe sentirse amontonada. Usar espaciado consistente, bordes suaves y agrupacion visual clara.

### 3. Identidad propia, no copia de Lightspeed

Se puede tomar como referencia la utilidad de pantallas tipo Lightspeed: tablas densas, detalle operativo, recepcion de mercancia. Pero no copiar:

- barras grises planas;
- sidebars con labels en ingles;
- accordions `Details / Items`;
- apariencia exacta tipo spreadsheet viejo.

Preferir lenguaje propio:

- `Resumen`;
- `Articulos`;
- `Recepcion`;
- `Costos`;
- `Pagos`;
- `Inventario`;
- `Proveedores`;
- `Referencias`;
- `Compatibilidad`;
- `Alternativas`.

## Lenguaje Visual

### Color

Usar color de forma funcional:

- `emerald / green`: flujo correcto, accion primaria, stock OK, recepcion correcta.
- `amber`: alerta, pendiente, reorden, advertencia.
- `red`: error, cancelacion, accion destructiva.
- `gray`: estructura, bordes, textos secundarios, fondos neutrales.
- `indigo`: etiquetas especiales como universalidad o informacion secundaria destacada.

Evitar una pantalla dominada por un solo color fuerte. El verde/emerald debe funcionar como acento, no como fondo completo.

### Componentes

Preferir:

- tarjetas operativas con borde y sombra sutil;
- radios moderados (`rounded-md`, `rounded-lg`);
- badges para estados;
- tablas con `divide-y` o bordes suaves;
- metric cards compactas;
- navegacion horizontal por secciones;
- iconos `lucide-vue-next` en acciones y encabezados.

Evitar:

- cards dentro de cards sin necesidad;
- sombras fuertes;
- fondos con gradientes decorativos;
- botones destructivos mezclados junto a acciones rutinarias sin separacion;
- texto visible que explique como usar la interfaz si la accion ya es clara.

## Patrones De Pantalla

### Header Operativo

Debe contener:

- titulo claro del recurso;
- identificador, SKU, numero de OC o estado;
- subtitulo contextual;
- acciones principales a la derecha;
- accion destructiva separada visualmente si existe.

Ejemplos:

- `Orden de compra #...`
- `Recepcion de OC`
- nombre del producto + SKU base

### Metricas Superiores

Usar cards compactas para datos que cambian decisiones:

- stock total;
- stock en almacen actual;
- pendiente;
- recibido;
- costo;
- precio;
- margen;
- alertas de reorden.

### Navegacion Interna

Para pantallas largas, usar navegacion horizontal compacta:

- `Resumen`
- `Articulos`
- `Inventario`
- `Proveedores`
- `Referencias`
- `Compatibilidad`
- `Alternativas`

Evitar sidebars copiados de otros sistemas salvo que el modulo realmente lo necesite.

### Tablas

Las tablas deben ser densas y legibles:

- encabezados en uppercase pequeno;
- filas con hover suave;
- numeros alineados a la derecha;
- SKU y codigos en fuente monoespaciada;
- estados como badges;
- columnas ajustadas al trabajo real.

En compras/inventario, las columnas importantes suelen ser:

- SKU;
- producto;
- proveedor;
- estado;
- cantidad;
- costo;
- total;
- stock;
- reorden.

## Aplicacion Por Modulo

### Ordenes De Compra

La OC debe sentirse como un workbench de compras:

- header con OC, proveedor, estado y total;
- secciones de resumen, articulos, recepcion, costos y pagos;
- tablas compactas;
- recepcion con estado por fila;
- resumen de cantidades;
- advertencias claras para POS preaplicado.

### Proveedores

El detalle de proveedor debe sentirse como una mesa de trabajo de abastecimiento:

- datos comerciales visibles sin ocupar demasiado espacio;
- marcas y productos suministrados como relaciones operativas, no como texto suelto;
- busqueda simple y paginacion cuando hay muchas relaciones;
- productos agrupados en submenus desplegables;
- costo, MOQ, lead time y proveedor preferido editables cerca de la relacion;
- acciones destructivas separadas o menos prominentes que las acciones de guardado/vinculacion.

### Inventario Y Movimientos

Las pantallas de inventario deben priorizar trazabilidad y ejecucion:

- almacen, stock actual, cantidad y motivo siempre visibles;
- producto/variante claramente identificados por nombre y SKU;
- tablas compactas para multiples lineas;
- advertencias visibles cuando una accion afecta disponibilidad;
- estados de carga y confirmacion sobrios, sin interrumpir el flujo si no es necesario.

### Caja, Cotizaciones Y Ventas

Las pantallas de venta deben seguir el mismo lenguaje operativo, pero con mayor foco en rapidez:

- busqueda y seleccion de variantes directa;
- totales, impuestos, descuentos y disponibilidad faciles de escanear;
- acciones primarias visibles y consistentes;
- advertencias de stock claras;
- evitar pasos modales encadenados si una sola vista puede resolver la seleccion.

### Reportes Y Paneles

Los reportes deben ser densos y comparables:

- metricas superiores compactas;
- filtros en una franja clara;
- tablas o graficas solo cuando ayudan a decidir;
- totales y diferencias alineados a la derecha;
- exportar/imprimir como acciones secundarias claras.

### Productos

El show de producto debe servir para validar compras e inventario:

- galeria visible;
- imagenes heredadas marcadas como heredadas;
- stock total y por almacen;
- proveedor preferido;
- costo, precio y margen;
- variantes;
- inventario;
- atributos;
- OEM;
- compatibilidad vehicular;
- alternativas.

La pantalla de producto no debe ser una ficha generica simple. Debe ayudar a decidir si se compra, se recibe o se reemplaza una pieza.

## Verificacion De Calidad Visual

Antes de cerrar una iteracion de UI:

- Confirmar que la pantalla se entiende desde el primer viewport.
- Confirmar que las acciones principales no compiten con acciones destructivas.
- Confirmar que tablas y cards no se ven amontonadas.
- Confirmar que el texto largo no rompe cards ni botones.
- Confirmar modo oscuro si la pantalla usa clases dark.
- Ejecutar Prettier solo en archivos tocados.
- Ejecutar `npm run build`.
- Usar `vue-tsc --noEmit` como verificacion secundaria, reportando errores base si existen fuera del scope.

## Regla De Oro

La interfaz debe sentirse como una herramienta profesional de inventario y compras para HermeX:

**rapida de leer, sobria, densa, accionable y propia.**
