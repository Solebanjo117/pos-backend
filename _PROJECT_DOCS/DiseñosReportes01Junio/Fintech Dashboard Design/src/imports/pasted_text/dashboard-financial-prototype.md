PAGE 1 — Dashboard Financiero
Prototype Flow
Sidebar > Reportes (expand submenu) > Estado de Resultados
                                   > Balance General
                                   > Ventas por Cliente
                                   > Cuentas por Cobrar
                                   > Cierre de Caja

Date Preset Buttons → Smart Animate to filtered state variants
KPI Cards → Navigate to detail pages
Chart segments → Overlay with breakdown tooltip
Transaction list items → Navigate to Page 7 (Perfil de Cliente)
Clickable Interactions
Element	Action	Destination/Behavior
Sidebar "Reportes"	On Click	Expand submenu (variant swap, Smart Animate height 200ms ease-out)
Sidebar submenu items	On Click	Navigate to respective page (Smart Animate slide-left 300ms)
"Mes" preset button	On Click	Swap to variant where "Mes" is active (indigo bg), charts update to "Mes" data variant
"Hoy" / "Semana" / "Trimestre" / "Año"	On Click	Same variant swap pattern, different data states
Date range picker	On Click	Open overlay calendar (Figma overlay, position below input, backdrop none)
Warehouse dropdown	On Click	Open overlay dropdown list (Smart Animate fade+scale 150ms)
KPI Card "Ingresos Totales"	On Click	Navigate to Page 2 (Estado de Resultados)
KPI Card "Cuentas por Cobrar"	On Click	Navigate to Page 5 (Cuentas por Cobrar)
KPI Card "Utilidad Neta"	On Click	Navigate to Page 2 (Estado de Resultados)
KPI Card "Gastos / COGS"	On Click	Navigate to Page 2 (scroll to COGS section)
Doughnut chart segment "Crédito"	On Click	Open overlay panel with credit breakdown
"Top 5 Clientes" bar item	On Click	Navigate to Page 7 (Perfil de Cliente)
"Últimas Transacciones" row	On Click	Open overlay slide-in drawer from right (320px wide)
Notification bell	On Click	Open overlay dropdown with 3 notification items
User avatar (top bar)	On Click	Open overlay dropdown: "Configuración", "Cerrar Sesión"
Smart Animate Usage
Sidebar submenu expand: Height animates from 0 to full. Items fade-in with stagger (each item Delay 50ms). Use Smart Animate, 200ms, ease-out.
Preset button active state: Background color transition gray→indigo, scale 1.0→1.02. Smart Animate, 150ms, ease-in-out.
KPI card number change: When switching presets, swap to frame with different numbers. Use Smart Animate, 400ms, ease-out — Figma will cross-fade the text layers.
Chart data change: Create 4 variants of the area chart (Hoy/Semana/Mes/Trimestre). Smart Animate between them morphs the path shapes. Smart Animate, 600ms, ease-in-out.
Doughnut segments: Variants with different segment sizes. Smart Animate rotates and resizes arcs. Smart Animate, 500ms, spring (stiffness 120, damping 14).
Transaction drawer slide-in: Overlay from right edge, Smart Animate, 300ms, ease-out, X position from 1440→1120.
Component Variants
Component: KPICard
  Variants:
    - State: Default / Hover / Active / Loading
    - Type: Revenue / Receivable / Profit / Expense
    - Trend: Up / Down / Neutral
  Properties:
    - icon-color: blue / orange / emerald / red
    - border-color: blue / orange / emerald / red
    - value: string
    - subtitle: string
    - sparkline: visible / hidden

Component: PresetButton
  Variants:
    - State: Default / Hover / Active
    - Position: First / Middle / Last / Single

Component: SidebarItem
  Variants:
    - State: Default / Hover / Active / Active+Expanded
    - HasSubmenu: true / false
    - Badge: none / number / dot

Component: TransactionRow
  Variants:
    - State: Default / Hover
    - PaymentBadge: Credit / Cash / Card / Yappy
    - AvatarColor: indigo / violet / emerald / amber

Component: WarehouseSelector
  Variants:
    - State: Closed / Open / Loading
    - Selected: "Sucursal Central" / "Sucursal Norte" / "Todas"
Hover States
KPI Cards: translateY(-2px), shadow increases from shadow-sm to shadow-md, border-left color brightens 10%, cursor pointer indicator appears (subtle arrow icon opacity 0→0.5).
Chart segments: Segment expands outward 4px (scale 1.03), tooltip overlay appears showing exact value + percentage.
Transaction rows: Background shifts from transparent to #252830, left padding increases 4px (Smart Animate), "Ver detalle" text appears right side opacity 0→1.
Sidebar items: Background #1a1d27 opacity 0→0.5, left border-2 indigo appears (width 0→2px Smart Animate).
Preset buttons: Background lightens 5%, scale 1.01.
Doughnut chart: Hovered segment lifts 8px outward, other segments dim to 60% opacity.
Fake Dynamic States
Loading skeleton: Create a full-page variant where all cards show skeleton placeholders (rounded rectangles with gradient shimmer — use a linear gradient fill animated via Figma's prototype auto-play). Duration: show for 1.5s then auto-navigate to loaded state.
Empty state: Variant where charts show "Sin datos para este período" with illustration. Triggered when "custom" date range selected with no data.
Data refresh: After clicking "Aplicar" filter, show 800ms loading variant (spinner on cards, charts dimmed to 30% opacity), then Smart Animate to populated state.
Real-time indicator: Small green dot pulsing (opacity 1→0.3→1, auto-animate loop 2s) next to "En vivo" text in header.
Navigation Logic
Dashboard → Page 2: Smart Animate slide-left 300ms
Dashboard → Page 3: Smart Animate slide-left 300ms
Dashboard → Page 4: Smart Animate slide-left 300ms
Dashboard → Page 5: Smart Animate slide-left 300ms
Dashboard → Page 6: Smart Animate slide-left 300ms
Dashboard → Page 7: Smart Animate slide-left 300ms
Any Page → Dashboard: Smart Animate slide-right 300ms
Sidebar collapse: Width 260→64px, Smart Animate 250ms, icons remain, labels hide
Overlay Modals
Date Range Picker Overlay: Position below date input, Backdrop: none, Close on click outside: yes. Calendar grid 7×5, current month highlighted, selected range in indigo-100 bg. "Aplicar" button at bottom.
Transaction Detail Drawer: Position right edge, width 360px, Backdrop: black/40, Close on backdrop click: yes. Contains: invoice header, client info, items list, payment breakdown, action buttons.
Notification Dropdown: Position below bell icon, width 320px, Backdrop: none. 3 notification items with timestamps.
Quick Export Menu: Position below export button, width 200px. Options: PDF, Excel, Imprimir. Each with icon.
Loading/Skeleton States
Frame: DashboardLoading (auto-play → DashboardLoaded after 1500ms)
  - 4 KPI skeleton cards: rounded-xl bg #252830, inner rectangles with shimmer gradient
  - Chart area: large rounded rectangle with shimmer
  - Table area: 5 rows of skeleton lines
  - Shimmer: linear-gradient 90deg, #252830 → #2e3039 → #252830, animated via
    prototype "After delay 100ms → scroll to next shimmer frame" loop
UX Improvements
Sticky header: When scrolling past KPI cards, header shrinks to compact bar (height 64→48px) with just title + filters. Smart Animate on scroll trigger.
Breadcrumb click: Each breadcrumb segment is clickable. "Reportes" opens sidebar submenu overlay.
Keyboard shortcut hint: Small ⌘K badge on search icon. Click opens command palette overlay (full screen, centered search input, recent items below).
Comparison mode: Toggle button "Comparar con período anterior" — when active, KPI cards show dual values with delta percentage badge (↑12% emerald or ↓5% red). Variant swap with Smart Animate.
Drag to reorder KPI cards: Visual hint with grip icon on hover. In prototype, simulate by showing "cards rearranging" animation variant.
Figma Limitations Handling
Limitation	Workaround
No real scroll in charts	Create 3 scroll position variants, link with "On drag" interaction
No real data binding	Pre-build 4-5 data state variants per chart, swap on filter click
No conditional logic	Use variable modes (Figma Variables) for light/dark theme toggle
No real date picker	Build 2-month calendar overlay, "selecting" swaps to variant with range highlighted
No real search	Build overlay with 3 pre-typed search results that appear after 300ms delay
Chart hover tooltips	Use "While hovering" → show tooltip overlay positioned near segment