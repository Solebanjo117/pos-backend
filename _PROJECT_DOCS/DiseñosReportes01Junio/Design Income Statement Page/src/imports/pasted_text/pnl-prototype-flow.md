PAGE 2 — Estado de Resultados
Prototype Flow
Filters → Apply → Smart Animate data refresh
P&L Table rows → Click to expand sub-detail
Export buttons → Open export options overlay
"Créditos Otorgados" row → Navigate to Page 5
Brand/Category chart segment → Overlay with detail card
"Personalizado" preset → Show custom date inputs with Smart Animate slide-down
Clickable Interactions
Element	Action	Behavior
Preset "Hoy"	On Click	Swap all data to "Hoy" variant, Smart Animate 400ms
Preset "Personalizado"	On Click	Reveal extra date inputs (slide-down 200ms), show "Aplicar" button
"Aplicar" button	On Click	800ms loading state (spinner), then swap to filtered data variant
Export "PDF"	On Click	Open overlay: "Generando PDF..." with progress bar (auto-close after 2s, show "PDF Listo ✓" toast)
Export "Excel"	On Click	Same pattern, different icon
"Imprimir"	On Click	Open overlay: print preview modal (A4 layout, white bg, centered)
P&L row "Ventas Brutas"	On Click	Expand sub-rows below (Smart Animate height 0→auto, 250ms)
P&L row "Devoluciones"	On Click	Expand showing top 3 return reasons
P&L row "COGS"	On Click	Expand showing cost breakdown by category
P&L row "Utilidad Neta"	On Click	Expand showing margin trend mini-chart
Doughnut segment (brand)	On Click	Open overlay card: brand detail with monthly trend
Doughnut segment (category)	On Click	Open overlay card: category detail with top products
"Créditos Otorgados" amount	On Click	Navigate to Page 5 (Cuentas por Cobrar)
Stacked payment bar segment	On Click	Show tooltip overlay with exact amount + %
Table header "CONCEPTO"	On Click	Toggle sort indicator (variant swap, icon rotates 180°)
Smart Animate Usage
P&L row expansion: Row height 48→auto. Sub-rows slide in from top with stagger (50ms each). Chevron icon rotates 0→90°. Smart Animate, 250ms, ease-out.
Data refresh on filter change: All number text layers cross-fade (opacity 0→1 while old values fade out). Cards briefly show skeleton shimmer (200ms). Smart Animate, 400ms, ease-in-out.
Preset button switch: Active button scales 1.0→1.05→1.0 (spring), bg color transitions. Inactive buttons dim slightly. Smart Animate, 200ms, spring.
Chart morphing: Area chart path morphs between data states. Doughnut segments resize and rotate. Smart Animate, 600ms, ease-in-out.
Export progress: Progress bar width 0→100% over 2000ms. Smart Animate, 2000ms, linear.
Print preview: Modal scales from 90%→100%, opacity 0→1. Smart Animate, 300ms, ease-out.
Component Variants
Component: PnLRow
  Variants:
    - Type: Header / SubItem / SubTotal / GrandTotal
    - Expanded: true / false
    - Hover: true / false
    - Sign: Positive / Negative / Neutral
  Properties:
    - indent: 0 / 24 / 48
    - has-expand: true / false
    - chevron-rotation: 0 / 90

Component: ProfitabilityCard
  Variants:
    - Type: Brand / Category
    - State: Default / Loading / Empty
    - ChartType: Doughnut / List-only
  Properties:
    - item-count: 3 / 5 / 7

Component: ExportButton
  Variants:
    - Type: PDF / Excel / Print
    - State: Default / Hover / Loading / Success
    - Icon: FileDown / Table / Printer

Component: StackedBar
  Variants:
    - DataState: Month / Week / Today / Quarter
    - HoveredSegment: none / Cash / Card / Transfer / Credit / Yappy

Component: FilterBar
  Variants:
    - ActivePreset: Hoy / Semana / Mes / Trimestre / Año / Personalizado
    - WarehouseOpen: true / false
Hover States
P&L rows: Background transparent→#252830, left padding shifts 4px right (Smart Animate), expand chevron opacity 0→0.7 appears.
Percentage badges: Scale 1.0→1.05, shadow increases.
Doughnut segments: Segment lifts 8px, tooltip appears, other segments dim to 50% opacity.
Export buttons: Background lightens, icon shifts up 2px, "Exportar" text appears if icon-only.
Stacked bar segments: Hovered segment brightens 15%, shows amount tooltip above.
Filter date inputs: Border color #2e3039→#6366f1, subtle glow ring appears.
Fake Dynamic States
Loading after filter apply: All data areas show skeleton for 800ms, then Smart Animate to populated state. Numbers "count up" simulated by 3 intermediate frames (0% → 50% → 80% → 100% of final value) shown at 100ms intervals.
Empty state for custom range: If "Personalizado" selected with future dates, show variant with "Sin datos para este período" illustration + "Seleccionar otro rango" button.
Export simulation: Click PDF → loading variant (2s) → success toast overlay (3s auto-dismiss) slides in from top-right.
Comparison mode: Toggle "vs Período Anterior" → cards show dual numbers with delta arrows. Variant swap with Smart Animate.
Navigation Logic
Page 2 → Page 5: On click "Créditos Otorgados" amount, Smart Animate slide-left
Page 2 → Page 4: On click brand/category detail "Ver ventas por cliente", slide-left
Page 2 → Page 1: Breadcrumb "Dashboard" click, Smart Animate slide-right
Page 2 → Page 3: Sidebar "Balance General" click, Smart Animate slide-left
Page 2 → Page 6: Sidebar "Cierre de Caja" click, Smart Animate slide-left
Overlay Modals
Brand Detail Card: Position center-right of chart, width 320px. Shows: brand name, monthly trend sparkline (3 variants: up/down/flat), top 3 products, "Ver más" link. Backdrop: none, Close on outside click.
Export Progress: Position center screen, width 400px, Backdrop: black/40. Progress bar + status text. Auto-closes after 2s, replaced by toast.
Print Preview: Position center, width 600px, height 80vh, Backdrop: black/60. White A4 page inside with formatted P&L. "Imprimir" + "Cerrar" buttons at bottom.
Row Expansion Detail: Not overlay — inline expansion. But sub-detail has its own overlay for "Ver facturas" → opens transaction list drawer from right.
Loading/Skeleton States
Frame: PnLLoading
  - Filter bar: fully rendered (always visible)
  - KPI cards: 4 skeleton cards with shimmer
  - P&L table: 8 skeleton rows with shimmer lines
  - Charts: 2 skeleton rectangles with shimmer
  - Auto-transition: After delay 1200ms → navigate to PnLLoaded

Frame: PnLRefreshing (triggered by filter change)
  - Filter bar: shows spinner on "Aplicar" button
  - All data areas: opacity 0.3 + spinner overlay centered
  - Auto-transition: After delay 800ms → navigate to PnLFiltered
UX Improvements
Sticky P&L header: When scrolling past KPI cards, the "Utilidad Neta" card sticks to top as a compact summary bar (height 48px, shows only the net profit number). Smart Animate morph.
Inline editing dates: Double-click on date input shows inline calendar. Single click shows native-style picker overlay.
Section collapse: Each major section (KPI Cards, P&L Table, Charts, Credit Breakdown) has a collapse toggle. Collapsed state shows only section title + summary number. Smart Animate height transition 300ms.
Scroll progress indicator: Thin indigo bar at top of page showing scroll position (3 variants: 25%, 50%, 75%, 100%).
Quick jump: Floating "↓" button appears after scrolling past first section. Clicks scroll to next section (swap to scrolled variant).
Figma Limitations Handling
Limitation	Workaround
No real table sorting	Build 2 variants (asc/desc), toggle on header click with icon rotation
No real inline expansion	Build expanded/collapsed variants of each row group, swap on click
No scroll sync between sections	Use "Scroll to" action to jump to section anchors
No real PDF generation	Show mock PDF preview image in overlay, "download" triggers toast
No real chart interaction	Pre-build hover states for each segment, show on "While hovering"
No real date validation	Build error variant of date input (red border + "Fecha inválida" text)