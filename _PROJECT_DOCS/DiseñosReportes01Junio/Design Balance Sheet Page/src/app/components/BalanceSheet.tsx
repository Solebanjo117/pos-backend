import { useState, useEffect, useRef } from "react";
import { motion, useInView } from "motion/react";
import {
  Scale, TrendingUp, AlertCircle, Shield, CheckCircle, Clock,
  FileText, Printer, Download, ChevronRight, LayoutDashboard,
  BarChart3, ShoppingCart, Users, Package, Settings, Bell, Search,
  ChevronDown, Warehouse, Receipt, PieChart as PieChartIcon, FileBarChart, BookOpen,
  Banknote, ArrowUpRight, ArrowDownRight, AlertTriangle, Activity,
  Info, Minus,
} from "lucide-react";
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell,
  PieChart, Pie, AreaChart, Area, CartesianGrid,
} from "recharts";

// ─── Design tokens ────────────────────────────────────────────────────────────
const C = {
  bg: "#0f1117",
  card: "#1a1d27",
  muted: "#252830",
  border: "#2e3039",
  blue: "#3b82f6",
  blueFaint: "rgba(59,130,246,0.08)",
  blueGlow: "rgba(59,130,246,0.18)",
  orange: "#f97316",
  orangeFaint: "rgba(249,115,22,0.08)",
  orangeGlow: "rgba(249,115,22,0.18)",
  emerald: "#22c55e",
  emeraldFaint: "rgba(34,197,94,0.08)",
  emeraldGlow: "rgba(34,197,94,0.18)",
  amber: "#f59e0b",
  red: "#ef4444",
  indigo: "#6366f1",
  indigoFaint: "rgba(99,102,241,0.08)",
  text: {
    primary: "#f1f5f9",
    secondary: "#94a3b8",
    muted: "#4b5563",
    label: "#64748b",
  },
  num: { fontVariantNumeric: "tabular-nums" as const, fontFamily: "'Inter', system-ui, sans-serif" },
};

// ─── Animated counter ─────────────────────────────────────────────────────────
function AnimatedNumber({ value, prefix = "$", decimals = 2, duration = 1400 }: {
  value: number; prefix?: string; decimals?: number; duration?: number;
}) {
  const [n, setN] = useState(0);
  const ref = useRef<HTMLSpanElement>(null);
  const inView = useInView(ref, { once: true, margin: "-40px" });

  useEffect(() => {
    if (!inView) return;
    let startTs = 0;
    const tick = (ts: number) => {
      if (!startTs) startTs = ts;
      const t = Math.min((ts - startTs) / duration, 1);
      const eased = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
      setN(eased * value);
      if (t < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }, [inView, value, duration]);

  return (
    <span ref={ref} style={C.num}>
      {prefix}{n.toLocaleString("es-MX", { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}
    </span>
  );
}

// ─── Dark tooltip shared style ────────────────────────────────────────────────
function DarkTooltip({ active, payload, label, formatter }: {
  active?: boolean; payload?: any[]; label?: string;
  formatter?: (v: number, name: string) => string;
}) {
  if (!active || !payload?.length) return null;
  return (
    <div style={{ background: "#1e2130", border: `1px solid ${C.border}`, borderRadius: 8, padding: "8px 12px", fontSize: 12, boxShadow: "0 8px 24px rgba(0,0,0,0.5)" }}>
      {label && <p style={{ color: C.text.muted, marginBottom: 4, fontWeight: 600, letterSpacing: "0.02em" }}>{label}</p>}
      {payload.map((p: any, i: number) => (
        <p key={i} style={{ color: p.color || C.text.primary, fontWeight: 600, ...C.num }}>
          {p.name && <span style={{ color: C.text.secondary, fontWeight: 400, marginRight: 4 }}>{p.name}:</span>}
          {formatter ? formatter(p.value, p.name) : `$${Number(p.value).toLocaleString("es-MX", { minimumFractionDigits: 2 })}`}
        </p>
      ))}
    </div>
  );
}

// ─── Sparkline using recharts AreaChart ───────────────────────────────────────
function Sparkline({ data, color }: { data: number[]; color: string }) {
  const chartData = data.map((v, i) => ({ i, v }));
  return (
    <AreaChart width={68} height={26} data={chartData} margin={{ top: 2, right: 0, bottom: 2, left: 0 }}>
      <defs>
        <linearGradient id={`sg-${color.replace("#", "")}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity={0.28} />
          <stop offset="100%" stopColor={color} stopOpacity={0} />
        </linearGradient>
      </defs>
      <Area
        type="monotone" dataKey="v"
        stroke={color} strokeWidth={1.5} fill={`url(#sg-${color.replace("#", "")})`}
        dot={false} isAnimationActive
      />
    </AreaChart>
  );
}

// ─── Sidebar ──────────────────────────────────────────────────────────────────
const navGroups = [
  {
    label: "Principal",
    items: [
      { icon: LayoutDashboard, label: "Dashboard", active: false },
      { icon: ShoppingCart, label: "Ventas", active: false },
      { icon: Package, label: "Inventario", active: false },
      { icon: Users, label: "Clientes", active: false },
    ],
  },
  {
    label: "Finanzas",
    items: [
      { icon: Receipt, label: "Cuentas por Cobrar", active: false },
      { icon: Banknote, label: "Cuentas por Pagar", active: false },
    ],
  },
  {
    label: "Reportes",
    items: [
      { icon: BarChart3, label: "Resumen", active: false },
      { icon: FileBarChart, label: "Estado de Resultados", active: false },
      { icon: Scale, label: "Balance General", active: true },
      { icon: PieChartIcon, label: "Flujo de Caja", active: false },
      { icon: BookOpen, label: "Libro Mayor", active: false },
    ],
  },
  { label: "Sistema", items: [{ icon: Settings, label: "Ajustes", active: false }] },
];

function Sidebar() {
  return (
    <aside className="fixed left-0 top-0 h-full flex flex-col z-30"
      style={{ width: 240, background: "#111318", borderRight: `1px solid ${C.border}` }}>
      <div className="flex items-center gap-2.5 px-4 h-14" style={{ borderBottom: `1px solid ${C.border}` }}>
        <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
          style={{ background: "linear-gradient(135deg,#6366f1,#8b5cf6)" }}>
          <Scale size={13} color="#fff" />
        </div>
        <div>
          <p style={{ fontWeight: 700, fontSize: 13, color: "#f1f5f9", lineHeight: 1.2 }}>HermeX POS</p>
          <p style={{ fontSize: 10, color: C.text.muted }}>Sistema Financiero</p>
        </div>
      </div>
      <nav className="flex-1 overflow-y-auto py-3 px-2">
        {navGroups.map((group) => (
          <div key={group.label} className="mb-4">
            <p style={{ fontSize: 9.5, fontWeight: 700, color: C.text.muted, letterSpacing: "0.1em", padding: "0 8px 4px", textTransform: "uppercase" }}>
              {group.label}
            </p>
            {group.items.map((item) => (
              <button key={item.label} className="w-full flex items-center gap-2.5 px-2.5 py-1.5 rounded-md mb-0.5 transition-colors"
                style={{ background: item.active ? "rgba(99,102,241,0.1)" : "transparent", borderLeft: item.active ? "2px solid #6366f1" : "2px solid transparent", color: item.active ? "#a5b4fc" : C.text.muted }}>
                <item.icon size={14} color={item.active ? "#6366f1" : C.text.muted} />
                <span style={{ fontSize: 12.5, fontWeight: item.active ? 600 : 400 }}>{item.label}</span>
              </button>
            ))}
          </div>
        ))}
      </nav>
      <div className="px-3 py-3" style={{ borderTop: `1px solid ${C.border}` }}>
        <div className="flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0"
            style={{ background: "linear-gradient(135deg,#6366f1,#8b5cf6)", fontSize: 11, color: "#fff", fontWeight: 700 }}>A</div>
          <div className="flex-1 min-w-0">
            <p style={{ fontSize: 12, fontWeight: 600, color: "#e2e8f0" }}>Administrador</p>
            <p style={{ fontSize: 10, color: C.text.muted }}>admin@hermex.mx</p>
          </div>
        </div>
      </div>
    </aside>
  );
}

// ─── Top bar ──────────────────────────────────────────────────────────────────
function TopBar() {
  return (
    <header className="fixed top-0 right-0 flex items-center justify-between px-5 z-20"
      style={{ left: 240, height: 56, background: C.bg, borderBottom: `1px solid ${C.border}` }}>
      <div className="flex items-center gap-1.5" style={{ fontSize: 12.5, color: C.text.muted }}>
        <LayoutDashboard size={13} />
        <span>Dashboard</span>
        <ChevronRight size={12} />
        <span>Reportes</span>
        <ChevronRight size={12} />
        <span style={{ color: "#a5b4fc", fontWeight: 500 }}>Balance General</span>
      </div>
      <div className="flex items-center gap-2">
        <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg" style={{ background: C.card, border: `1px solid ${C.border}` }}>
          <Search size={12} color={C.text.muted} />
          <span style={{ fontSize: 12, color: C.text.muted }}>Buscar...</span>
        </div>
        <button className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg" style={{ background: C.card, border: `1px solid ${C.border}` }}>
          <Warehouse size={12} color={C.indigo} />
          <span style={{ fontSize: 12, color: C.text.secondary }}>Sucursal Principal</span>
          <ChevronDown size={11} color={C.text.muted} />
        </button>
        <button className="w-7 h-7 rounded-lg flex items-center justify-center relative" style={{ background: C.card, border: `1px solid ${C.border}` }}>
          <Bell size={13} color={C.text.muted} />
          <span className="absolute top-1 right-1 w-1.5 h-1.5 rounded-full" style={{ background: C.indigo }} />
        </button>
        <div className="w-7 h-7 rounded-full flex items-center justify-center"
          style={{ background: "linear-gradient(135deg,#6366f1,#8b5cf6)", fontSize: 11, color: "#fff", fontWeight: 700 }}>A</div>
      </div>
    </header>
  );
}

// ─── Equation Banner ──────────────────────────────────────────────────────────
function EquationBanner() {
  const ref = useRef<HTMLDivElement>(null);
  const inView = useInView(ref, { once: true });

  const blocks = [
    { label: "ACTIVOS", value: 125400, color: C.blue, glow: C.blueGlow, faint: C.blueFaint, pct: null, icon: TrendingUp },
    { label: "PASIVOS", value: 42800, color: C.orange, glow: C.orangeGlow, faint: C.orangeFaint, pct: "34.1% del activo", icon: AlertCircle },
    { label: "PATRIMONIO", value: 82600, color: C.emerald, glow: C.emeraldGlow, faint: C.emeraldFaint, pct: "65.9% del activo", icon: Shield },
  ];

  return (
    <motion.div ref={ref} initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.45 }}
      className="relative rounded-xl mb-5 overflow-hidden"
      style={{ background: "linear-gradient(135deg,rgba(30,27,75,0.6),rgba(46,16,101,0.4))", border: "1px solid rgba(99,102,241,0.15)", backdropFilter: "blur(20px)" }}>
      <div className="absolute inset-0 pointer-events-none"
        style={{ background: "radial-gradient(ellipse 60% 80% at 50% 0%, rgba(99,102,241,0.07), transparent)" }} />

      <div className="relative px-6 py-5">
        <div className="flex items-center justify-center gap-3">
          {blocks.map((block, i) => (
            <div key={block.label} className="flex items-center gap-3">
              <motion.div
                initial={{ opacity: 0, scale: 0.94 }}
                animate={inView ? { opacity: 1, scale: 1 } : {}}
                transition={{ duration: 0.5, delay: i * 0.12 }}
                className="relative flex flex-col items-center gap-1 px-8 py-4 rounded-xl cursor-default group"
                style={{ background: block.faint, border: `1px solid ${block.glow}` }}>
                <div className="absolute inset-0 rounded-xl opacity-0 group-hover:opacity-100 transition-opacity duration-300"
                  style={{ boxShadow: `0 0 28px ${block.glow}`, pointerEvents: "none" }} />
                <div className="flex items-center gap-1.5 mb-0.5">
                  <block.icon size={12} color={block.color} />
                  <span style={{ fontSize: 10, fontWeight: 700, color: block.color, letterSpacing: "0.1em" }}>{block.label}</span>
                </div>
                <span style={{ fontSize: 26, fontWeight: 700, color: block.color, lineHeight: 1.1, ...C.num }}>
                  <AnimatedNumber value={block.value} duration={1200} />
                </span>
                {block.pct && (
                  <span style={{ fontSize: 11, color: block.color, opacity: 0.7, marginTop: 2 }}>{block.pct}</span>
                )}
              </motion.div>
              {i < blocks.length - 1 && (
                <motion.div initial={{ opacity: 0 }} animate={inView ? { opacity: 1 } : {}} transition={{ delay: 0.35 + i * 0.12 }}>
                  <span style={{ fontSize: 22, fontWeight: 300, color: C.text.muted, userSelect: "none" }}>
                    {i === 0 ? "=" : "+"}
                  </span>
                </motion.div>
              )}
            </div>
          ))}
        </div>
        <motion.div initial={{ opacity: 0 }} animate={inView ? { opacity: 1 } : {}} transition={{ delay: 0.55 }}
          className="flex items-center justify-center gap-2 mt-4 pt-4" style={{ borderTop: "1px solid rgba(99,102,241,0.12)" }}>
          <CheckCircle size={13} color={C.emerald} />
          <span style={{ fontSize: 12, color: "#4ade80", fontWeight: 500 }}>Ecuación contable fundamental verificada</span>
          <span style={{ fontSize: 11, color: C.text.muted }}>· Al 26 de Mayo, 2026</span>
        </motion.div>
      </div>
    </motion.div>
  );
}

// ─── Accounting line item ─────────────────────────────────────────────────────
function AccountingRow({ label, amount, isNegative = false, bold = false, dim = false, note }: {
  label: string; amount: string; isNegative?: boolean; bold?: boolean; dim?: boolean; note?: string;
}) {
  const [hovered, setHovered] = useState(false);
  return (
    <div className="flex items-center gap-2 transition-colors cursor-default"
      style={{ padding: "4px 8px", borderRadius: 4, background: hovered ? C.muted : "transparent", minHeight: 28 }}
      onMouseEnter={() => setHovered(true)} onMouseLeave={() => setHovered(false)}>
      <span style={{ flex: 1, fontSize: 12.5, color: dim ? C.text.muted : C.text.secondary, fontWeight: bold ? 600 : 400, fontStyle: dim ? "italic" : "normal" }}>
        {label}
        {note && <span style={{ fontSize: 11, color: C.text.muted, marginLeft: 4 }}>({note})</span>}
      </span>
      <div className="flex-shrink-0" style={{ width: 24, height: 1, borderBottom: `1px dotted ${C.border}`, marginBottom: 1 }} />
      <span style={{ fontSize: 12.5, fontWeight: bold ? 600 : 400, color: isNegative ? C.red : bold ? C.text.primary : C.text.secondary, whiteSpace: "nowrap", ...C.num }}>
        {isNegative ? `(${amount.replace("-", "").replace("$", "")})` : amount}
      </span>
    </div>
  );
}

function AccountingSubtotal({ label, amount, color = C.text.secondary }: { label: string; amount: string; color?: string }) {
  return (
    <div className="flex items-center gap-2 px-2 pt-1.5 mt-1" style={{ borderTop: `1px solid ${C.border}` }}>
      <span style={{ flex: 1, fontSize: 12, color: C.text.label, fontWeight: 600, letterSpacing: "0.01em" }}>{label}</span>
      <span style={{ fontSize: 12.5, fontWeight: 700, color, ...C.num }}>{amount}</span>
    </div>
  );
}

function AccountingGroupHeader({ title }: { title: string }) {
  return (
    <div className="px-2 py-1.5 mb-0.5 rounded-md" style={{ background: "rgba(37,40,48,0.7)" }}>
      <span style={{ fontSize: 10, fontWeight: 700, color: C.text.muted, letterSpacing: "0.1em", textTransform: "uppercase" }}>
        {title}
      </span>
    </div>
  );
}

// ─── Accounting column ────────────────────────────────────────────────────────
function AccountingColumn({ title, icon: Icon, iconColor, totalAmount, totalColor, totalFaint, totalGlow, badgeColor, badgeFaint, delay, children }: {
  title: string; icon: React.ElementType; iconColor: string; totalAmount: number;
  totalColor: string; totalFaint: string; totalGlow: string; badgeColor: string; badgeFaint: string;
  delay: number; children: React.ReactNode;
}) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, delay, ease: [0.22, 1, 0.36, 1] }}
      className="flex flex-col rounded-xl overflow-hidden"
      style={{ background: C.card, border: `1px solid ${C.border}` }}>
      <div className="flex items-center justify-between px-4 py-3"
        style={{ borderBottom: `1px solid ${C.border}`, background: "rgba(15,17,23,0.5)" }}>
        <div className="flex items-center gap-2">
          <Icon size={15} color={iconColor} />
          <span style={{ fontWeight: 700, fontSize: 13.5, color: C.text.primary }}>{title}</span>
        </div>
        <span className="px-2.5 py-0.5 rounded-md" style={{ background: badgeFaint, color: badgeColor, fontSize: 12, fontWeight: 700, ...C.num }}>
          <AnimatedNumber value={totalAmount} />
        </span>
      </div>
      <div className="flex-1 px-3 py-3 space-y-2">{children}</div>
      <div className="m-3 mt-0 rounded-lg px-4 py-3 flex items-center justify-between relative overflow-hidden"
        style={{ background: totalFaint, border: `1px solid ${totalGlow}` }}>
        <div className="absolute inset-x-0 top-0 h-px"
          style={{ background: `linear-gradient(90deg, transparent, ${totalColor}55, transparent)` }} />
        <span style={{ fontSize: 11, fontWeight: 700, color: totalColor, letterSpacing: "0.08em", opacity: 0.8 }}>
          TOTAL {title.toUpperCase()}
        </span>
        <span style={{ fontSize: 17, fontWeight: 700, color: totalColor, ...C.num }}>
          <AnimatedNumber value={totalAmount} />
        </span>
      </div>
    </motion.div>
  );
}

// ─── Balance overview chart (vertical grouped bar) ────────────────────────────
const balanceOverviewData = [
  { name: "Activos", value: 125400, color: C.blue },
  { name: "Pasivos", value: 42800, color: C.orange },
  { name: "Patrimonio", value: 82600, color: C.emerald },
];

function BalanceOverviewChart() {
  return (
    <ResponsiveContainer width="100%" height={200}>
      <BarChart data={balanceOverviewData} margin={{ top: 8, right: 4, bottom: 0, left: 4 }} barSize={36}>
        <CartesianGrid vertical={false} stroke={C.border} strokeDasharray="3 3" />
        <XAxis
          dataKey="name" tick={{ fill: C.text.muted, fontSize: 11.5, fontWeight: 600 }}
          axisLine={false} tickLine={false}
        />
        <YAxis
          tickFormatter={(v) => `$${(v / 1000).toFixed(0)}K`}
          tick={{ fill: C.text.muted, fontSize: 10.5 }} axisLine={false} tickLine={false} width={40}
        />
        <Tooltip
          content={<DarkTooltip />}
          cursor={{ fill: "rgba(255,255,255,0.03)", radius: 4 }}
        />
        <Bar dataKey="value" radius={[4, 4, 0, 0]} isAnimationActive animationDuration={900} animationEasing="ease-out">
          {balanceOverviewData.map((entry) => (
            <Cell key={entry.name} fill={entry.color} fillOpacity={0.85} />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}

// ─── Composition donut chart ──────────────────────────────────────────────────
const compositionData = [
  { name: "Pasivos", value: 42800, color: C.orange, pct: "34.1%" },
  { name: "Patrimonio", value: 82600, color: C.emerald, pct: "65.9%" },
];

function renderDonutLabel({ cx, cy, midAngle, innerRadius, outerRadius, percent, name }: any) {
  const RADIAN = Math.PI / 180;
  const r = innerRadius + (outerRadius - innerRadius) * 0.5;
  const x = cx + r * Math.cos(-midAngle * RADIAN);
  const y = cy + r * Math.sin(-midAngle * RADIAN);
  return (
    <text x={x} y={y} fill="#fff" textAnchor="middle" dominantBaseline="central"
      style={{ fontSize: 11, fontWeight: 700, pointerEvents: "none" }}>
      {`${(percent * 100).toFixed(1)}%`}
    </text>
  );
}

function CompositionDonut() {
  return (
    <div className="flex flex-col items-center">
      <div style={{ position: "relative", width: 180, height: 180 }}>
        <PieChart width={180} height={180}>
          <Pie
            data={compositionData} cx={85} cy={85}
            innerRadius={52} outerRadius={82}
            dataKey="value" strokeWidth={0}
            isAnimationActive animationDuration={900} animationEasing="ease-out"
            labelLine={false} label={renderDonutLabel}
          >
            {compositionData.map((entry) => (
              <Cell key={entry.name} fill={entry.color} fillOpacity={0.85} />
            ))}
          </Pie>
          <Tooltip content={<DarkTooltip />} />
        </PieChart>
        {/* Center label */}
        <div style={{ position: "absolute", inset: 0, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", pointerEvents: "none" }}>
          <span style={{ fontSize: 10, color: C.text.muted, fontWeight: 600, letterSpacing: "0.06em" }}>ACTIVOS</span>
          <span style={{ fontSize: 13, fontWeight: 700, color: C.text.primary, ...C.num }}>100%</span>
        </div>
      </div>
      {/* Legend */}
      <div className="flex flex-col gap-1.5 w-full px-2">
        {compositionData.map((d) => (
          <div key={d.name} className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-sm flex-shrink-0" style={{ background: d.color }} />
              <span style={{ fontSize: 12, color: C.text.secondary }}>{d.name}</span>
            </div>
            <div className="flex items-center gap-2">
              <span style={{ fontSize: 12, color: C.text.muted, ...C.num }}>${(d.value / 1000).toFixed(1)}K</span>
              <span className="px-1.5 py-0.5 rounded" style={{ fontSize: 10.5, fontWeight: 700, background: `${d.color}18`, color: d.color }}>{d.pct}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Ratio card with recharts sparkline ───────────────────────────────────────
function RatioCard({ label, displayValue, color, faint, borderColor, status, statusLabel, trend, trendLabel, sparkData, description, healthPct, delay }: {
  label: string; displayValue: string; color: string; faint: string; borderColor: string;
  status: "healthy" | "warning" | "risk"; statusLabel: string; trend: "up" | "down" | "flat"; trendLabel: string;
  sparkData: number[]; description: string; healthPct: number; delay: number;
}) {
  const [hovered, setHovered] = useState(false);
  const statusColors = { healthy: C.emerald, warning: C.amber, risk: C.red };
  const statusBg = { healthy: "rgba(34,197,94,0.1)", warning: "rgba(245,158,11,0.1)", risk: "rgba(239,68,68,0.1)" };
  const TrendIcon = trend === "up" ? ArrowUpRight : trend === "down" ? ArrowDownRight : Minus;
  const trendColor = trend === "up" ? C.emerald : trend === "down" ? C.red : C.text.muted;
  const barRef = useRef<HTMLDivElement>(null);
  const inView = useInView(barRef, { once: true });

  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }} whileInView={{ opacity: 1, y: 0 }} viewport={{ once: true }}
      transition={{ duration: 0.45, delay }}
      className="rounded-xl p-4 flex flex-col gap-3 transition-all cursor-default"
      style={{ background: hovered ? "rgba(37,40,48,0.9)" : C.muted, border: `1px solid ${hovered ? borderColor : C.border}`, transition: "all 0.2s ease" }}
      onMouseEnter={() => setHovered(true)} onMouseLeave={() => setHovered(false)}>
      <div className="flex items-start justify-between gap-2">
        <span style={{ fontSize: 11.5, fontWeight: 600, color: C.text.label, letterSpacing: "0.02em" }}>{label}</span>
        <span className="px-2 py-0.5 rounded-md flex-shrink-0"
          style={{ fontSize: 10, fontWeight: 700, background: statusBg[status], color: statusColors[status], letterSpacing: "0.04em" }}>
          {statusLabel}
        </span>
      </div>
      <div className="flex items-end justify-between">
        <span style={{ fontSize: 28, fontWeight: 700, color, lineHeight: 1, ...C.num }}>{displayValue}</span>
        <Sparkline data={sparkData} color={color} />
      </div>
      {/* Health bar */}
      <div ref={barRef} className="h-0.5 rounded-full overflow-hidden w-full" style={{ background: C.border }}>
        <motion.div className="h-full rounded-full"
          initial={{ width: 0 }} animate={{ width: inView ? `${Math.min(healthPct, 100)}%` : 0 }}
          transition={{ duration: 1.1, ease: [0.22, 1, 0.36, 1] }}
          style={{ background: color }} />
      </div>
      <div className="flex items-center justify-between">
        <p style={{ fontSize: 11, color: C.text.muted }}>{description}</p>
        <div className="flex items-center gap-0.5 flex-shrink-0 ml-2">
          <TrendIcon size={12} color={trendColor} />
          <span style={{ fontSize: 11, color: trendColor, fontWeight: 600 }}>{trendLabel}</span>
        </div>
      </div>
    </motion.div>
  );
}

// ─── Aging data ───────────────────────────────────────────────────────────────
const agingData = [
  { label: "Corriente", range: "0–30 días", amount: 6200, color: C.emerald, risk: "Sin Riesgo", riskLevel: 0, clients: 8, statusLabel: "Al día" },
  { label: "Vencido Bajo", range: "31–60 días", amount: 3800, color: C.amber, risk: "Riesgo Bajo", riskLevel: 1, clients: 5, statusLabel: "Seguimiento" },
  { label: "Vencido Medio", range: "61–90 días", amount: 1680, color: C.orange, risk: "Riesgo Medio", riskLevel: 2, clients: 3, statusLabel: "Urgente" },
  { label: "Crítico", range: "90+ días", amount: 800, color: C.red, risk: "Riesgo Alto", riskLevel: 3, clients: 2, statusLabel: "Incobrable" },
];
const agingTotal = agingData.reduce((s, d) => s + d.amount, 0);

// ─── Aging horizontal bar chart ───────────────────────────────────────────────
function AgingBarChart() {
  const chartData = agingData.map(d => ({
    name: d.range,
    amount: d.amount,
    pct: +((d.amount / agingTotal) * 100).toFixed(1),
    color: d.color,
  }));

  return (
    <ResponsiveContainer width="100%" height={160}>
      <BarChart data={chartData} layout="vertical" margin={{ top: 0, right: 60, bottom: 0, left: 0 }} barSize={16}>
        <XAxis
          type="number" dataKey="amount"
          tickFormatter={(v) => `$${(v / 1000).toFixed(0)}K`}
          tick={{ fill: C.text.muted, fontSize: 10.5 }} axisLine={false} tickLine={false}
        />
        <YAxis
          type="category" dataKey="name" width={80}
          tick={{ fill: C.text.secondary, fontSize: 11.5 }} axisLine={false} tickLine={false}
        />
        <Tooltip
          content={<DarkTooltip formatter={(v) => `$${Number(v).toLocaleString("es-MX", { minimumFractionDigits: 2 })}`} />}
          cursor={{ fill: "rgba(255,255,255,0.03)" }}
        />
        <Bar dataKey="amount" radius={[0, 4, 4, 0]} isAnimationActive animationDuration={900} animationEasing="ease-out"
          label={{ position: "right", formatter: (v: number) => `${((v / agingTotal) * 100).toFixed(1)}%`, fill: C.text.muted, fontSize: 11, fontWeight: 600 }}>
          {chartData.map((entry) => (
            <Cell key={entry.name} fill={entry.color} fillOpacity={0.8} />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}

// ─── Aging section ────────────────────────────────────────────────────────────
function AgingSection() {
  const overdueTotal = agingData.filter(d => d.riskLevel > 0).reduce((s, d) => s + d.amount, 0);
  const riskColors = [C.emerald, C.amber, C.orange, C.red];
  const riskBg = ["rgba(34,197,94,0.1)", "rgba(245,158,11,0.1)", "rgba(249,115,22,0.1)", "rgba(239,68,68,0.1)"];

  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }} whileInView={{ opacity: 1, y: 0 }} viewport={{ once: true }}
      transition={{ duration: 0.5 }}
      className="rounded-xl overflow-hidden" style={{ background: C.card, border: `1px solid ${C.border}` }}>
      <div className="flex items-center justify-between px-5 py-3.5"
        style={{ borderBottom: `1px solid ${C.border}`, background: "rgba(15,17,23,0.4)" }}>
        <div className="flex items-center gap-2">
          <Clock size={15} color={C.orange} />
          <span style={{ fontWeight: 700, fontSize: 13.5, color: C.text.primary }}>Antigüedad de Cuentas por Cobrar</span>
        </div>
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2 px-3 py-1.5 rounded-lg"
            style={{ background: "rgba(34,197,94,0.08)", border: "1px solid rgba(34,197,94,0.15)" }}>
            <Activity size={12} color={C.emerald} />
            <span style={{ fontSize: 11.5, color: C.emerald, fontWeight: 600 }}>Salud de Cobro 82%</span>
          </div>
          {overdueTotal > 0 && (
            <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg"
              style={{ background: "rgba(239,68,68,0.08)", border: "1px solid rgba(239,68,68,0.15)" }}>
              <AlertTriangle size={12} color={C.red} />
              <span style={{ fontSize: 11.5, color: C.red, fontWeight: 600 }}>
                ${overdueTotal.toLocaleString("es-MX")} vencido
              </span>
            </div>
          )}
        </div>
      </div>

      <div className="p-5">
        {/* Recharts horizontal bar chart */}
        <AgingBarChart />

        {/* Table */}
        <div className="rounded-lg overflow-hidden mt-4" style={{ border: `1px solid ${C.border}` }}>
          <table className="w-full">
            <thead>
              <tr style={{ background: "rgba(37,40,48,0.8)" }}>
                {["Antigüedad", "Monto", "% Total", "Clientes", "Estado", "Riesgo"].map(h => (
                  <th key={h} className="text-left px-3 py-2.5"
                    style={{ fontSize: 11, fontWeight: 600, color: C.text.muted, letterSpacing: "0.04em" }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {agingData.map((item, i) => (
                <tr key={item.label} className="transition-colors cursor-default"
                  style={{ borderTop: `1px solid ${C.border}` }}
                  onMouseEnter={e => (e.currentTarget.style.background = C.muted)}
                  onMouseLeave={e => (e.currentTarget.style.background = "transparent")}>
                  <td className="px-3 py-2.5">
                    <div className="flex items-center gap-2">
                      <span className="w-1.5 h-1.5 rounded-full" style={{ background: item.color }} />
                      <div>
                        <p style={{ fontSize: 12.5, color: C.text.primary, fontWeight: 500 }}>{item.label}</p>
                        <p style={{ fontSize: 11, color: C.text.muted }}>{item.range}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-3 py-2.5" style={{ fontSize: 13, fontWeight: 600, color: C.text.primary, ...C.num }}>
                    ${item.amount.toLocaleString("es-MX", { minimumFractionDigits: 2 })}
                  </td>
                  <td className="px-3 py-2.5">
                    <span className="px-2 py-0.5 rounded-md"
                      style={{ fontSize: 11, background: `${item.color}18`, color: item.color, fontWeight: 600, ...C.num }}>
                      {((item.amount / agingTotal) * 100).toFixed(1)}%
                    </span>
                  </td>
                  <td className="px-3 py-2.5" style={{ fontSize: 12, color: C.text.secondary }}>{item.clients}</td>
                  <td className="px-3 py-2.5">
                    <span className="px-2 py-0.5 rounded-md"
                      style={{ fontSize: 11, background: riskBg[i], color: riskColors[i], fontWeight: 600 }}>
                      {item.statusLabel}
                    </span>
                  </td>
                  <td className="px-3 py-2.5">
                    {item.riskLevel === 3 && (
                      <div className="flex items-center gap-1">
                        <AlertTriangle size={11} color={C.red} />
                        <span style={{ fontSize: 11, color: C.red, fontWeight: 600 }}>Crítico</span>
                      </div>
                    )}
                    {item.riskLevel === 2 && (
                      <div className="flex items-center gap-1">
                        <Info size={11} color={C.orange} />
                        <span style={{ fontSize: 11, color: C.orange, fontWeight: 600 }}>Medio</span>
                      </div>
                    )}
                    {item.riskLevel === 1 && <span style={{ fontSize: 11, color: C.amber }}>Bajo</span>}
                    {item.riskLevel === 0 && <span style={{ fontSize: 11, color: C.emerald }}>—</span>}
                  </td>
                </tr>
              ))}
              <tr style={{ borderTop: `2px solid ${C.border}`, background: "rgba(37,40,48,0.5)" }}>
                <td className="px-3 py-2.5" style={{ fontSize: 12, fontWeight: 700, color: C.text.primary }}>TOTAL</td>
                <td className="px-3 py-2.5" style={{ fontSize: 13, fontWeight: 700, color: C.text.primary, ...C.num }}>
                  ${agingTotal.toLocaleString("es-MX", { minimumFractionDigits: 2 })}
                </td>
                <td className="px-3 py-2.5" style={{ fontSize: 12, fontWeight: 700, color: C.text.primary }}>100%</td>
                <td className="px-3 py-2.5" style={{ fontSize: 12, color: C.text.secondary }}>18</td>
                <td className="px-3 py-2.5" colSpan={2} />
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </motion.div>
  );
}

// ─── Main ─────────────────────────────────────────────────────────────────────
export function BalanceSheet() {
  const ratioCards = [
    {
      label: "Razón Corriente", displayValue: "2.53",
      color: C.emerald, faint: C.emeraldFaint, borderColor: C.emeraldGlow,
      status: "healthy" as const, statusLabel: "SALUDABLE",
      trend: "up" as const, trendLabel: "+0.18",
      sparkData: [2.08, 2.21, 2.15, 2.35, 2.41, 2.48, 2.53],
      description: "Activo Corriente / Pasivo Corriente", healthPct: 75, delay: 0,
    },
    {
      label: "Endeudamiento", displayValue: "34.1%",
      color: C.amber, faint: "rgba(245,158,11,0.08)", borderColor: "rgba(245,158,11,0.2)",
      status: "warning" as const, statusLabel: "MODERADO",
      trend: "down" as const, trendLabel: "-1.2pp",
      sparkData: [37.8, 36.4, 35.9, 36.1, 34.8, 33.9, 34.1],
      description: "Pasivo Total / Activo Total", healthPct: 34.1, delay: 0.08,
    },
    {
      label: "ROE", displayValue: "21.2%",
      color: C.emerald, faint: C.emeraldFaint, borderColor: C.emeraldGlow,
      status: "healthy" as const, statusLabel: "ÓPTIMO",
      trend: "up" as const, trendLabel: "+2.4pp",
      sparkData: [17.8, 18.6, 19.4, 19.1, 20.5, 20.9, 21.2],
      description: "Utilidad Neta / Patrimonio", healthPct: 65, delay: 0.16,
    },
  ];

  return (
    <div className="min-h-screen" style={{ background: C.bg, fontFamily: "'Inter', system-ui, sans-serif" }}>
      <Sidebar />
      <TopBar />

      <main style={{ marginLeft: 240, paddingTop: 56 }}>
        <div className="p-5" style={{ maxWidth: 1360 }}>

          {/* ── Header ── */}
          <motion.div initial={{ opacity: 0, y: -12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}
            className="flex items-start justify-between mb-5">
            <div>
              <div className="flex items-center gap-2.5 mb-0.5">
                <Scale size={18} color={C.indigo} />
                <h1 style={{ fontSize: 22, fontWeight: 700, color: C.text.primary, margin: 0 }}>Balance General</h1>
                <span className="px-2 py-0.5 rounded-md ml-1"
                  style={{ fontSize: 10.5, fontWeight: 600, background: C.indigoFaint, color: "#a5b4fc", letterSpacing: "0.04em" }}>AUDITADO</span>
              </div>
              <p style={{ fontSize: 12.5, color: C.text.muted }}>Estado de Situación Financiera · Activos, Pasivos y Patrimonio</p>
            </div>
            <div className="flex items-center gap-2">
              <button className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg"
                style={{ background: C.card, border: `1px solid ${C.border}`, fontSize: 12, color: C.text.secondary }}>
                <Scale size={12} color={C.indigo} />
                Al 26 de Mayo, 2026
                <ChevronDown size={11} color={C.text.muted} />
              </button>
              {[{ icon: FileText, label: "PDF" }, { icon: Download, label: "Excel" }, { icon: Printer, label: "Imprimir" }].map(b => (
                <button key={b.label} className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg"
                  style={{ background: C.card, border: `1px solid ${C.border}`, fontSize: 12, color: C.text.muted }}>
                  <b.icon size={12} />{b.label}
                </button>
              ))}
            </div>
          </motion.div>

          {/* ── Equation Banner ── */}
          <EquationBanner />

          {/* ── Three Columns ── */}
          <div className="grid gap-4 mb-5" style={{ gridTemplateColumns: "1fr 1fr 1fr" }}>
            <AccountingColumn title="Activos" icon={TrendingUp} iconColor={C.blue}
              totalAmount={125400} totalColor="#60a5fa" totalFaint={C.blueFaint}
              totalGlow="rgba(59,130,246,0.2)" badgeColor="#60a5fa" badgeFaint={C.blueFaint} delay={0.1}>
              <div className="space-y-1">
                <AccountingGroupHeader title="Activo Corriente" />
                <AccountingRow label="Efectivo en Caja" amount="$18,500.00" />
                <AccountingRow label="Bancos" amount="$32,000.00" />
                <AccountingRow label="Cuentas por Cobrar" amount="$12,480.00" />
                <AccountingRow label="Inventario" amount="$45,200.00" note="a valor de costo" />
                <AccountingSubtotal label="Subtotal Corriente" amount="$108,180.00" color="#60a5fa" />
              </div>
              <div className="space-y-1 mt-3">
                <AccountingGroupHeader title="Activo No Corriente" />
                <AccountingRow label="Mobiliario y Equipo" amount="$15,000.00" />
                <AccountingRow label="Depreciación Acumulada" amount="-$2,780.00" isNegative />
                <AccountingRow label="Equipo de Cómputo" amount="$5,000.00" />
                <AccountingSubtotal label="Subtotal No Corriente" amount="$17,220.00" color="#60a5fa" />
              </div>
            </AccountingColumn>

            <AccountingColumn title="Pasivos" icon={AlertCircle} iconColor={C.orange}
              totalAmount={42800} totalColor="#fb923c" totalFaint={C.orangeFaint}
              totalGlow="rgba(249,115,22,0.2)" badgeColor="#fb923c" badgeFaint={C.orangeFaint} delay={0.2}>
              <div className="space-y-1">
                <AccountingGroupHeader title="Pasivo Corriente" />
                <AccountingRow label="Cuentas por Pagar" amount="$28,500.00" note="proveedores" />
                <AccountingRow label="Impuestos por Pagar" amount="$8,300.00" />
                <AccountingRow label="Créditos Clientes" amount="$6,000.00" note="pendientes" />
                <AccountingSubtotal label="Subtotal Corriente" amount="$42,800.00" color="#fb923c" />
              </div>
              <div className="space-y-1 mt-3">
                <AccountingGroupHeader title="Pasivo No Corriente" />
                <AccountingRow label="Sin registros" amount="$0.00" dim />
                <AccountingSubtotal label="Subtotal No Corriente" amount="$0.00" color={C.text.muted} />
              </div>
            </AccountingColumn>

            <AccountingColumn title="Patrimonio" icon={Shield} iconColor={C.emerald}
              totalAmount={82600} totalColor="#4ade80" totalFaint={C.emeraldFaint}
              totalGlow="rgba(34,197,94,0.2)" badgeColor="#4ade80" badgeFaint={C.emeraldFaint} delay={0.3}>
              <div className="space-y-1">
                <AccountingGroupHeader title="Capital y Reservas" />
                <AccountingRow label="Capital Social" amount="$50,000.00" />
                <AccountingRow label="Utilidades Retenidas" amount="$15,130.00" />
                <AccountingRow label="Utilidad del Período" amount="$17,470.00" bold />
                <AccountingSubtotal label="Total Patrimonio" amount="$82,600.00" color="#4ade80" />
              </div>
            </AccountingColumn>
          </div>

          {/* ── Charts + Ratios ── */}
          <motion.div
            initial={{ opacity: 0, y: 16 }} whileInView={{ opacity: 1, y: 0 }} viewport={{ once: true }}
            transition={{ duration: 0.45 }}
            className="rounded-xl mb-4" style={{ background: C.card, border: `1px solid ${C.border}` }}>
            <div className="flex items-center justify-between px-5 py-3.5"
              style={{ borderBottom: `1px solid ${C.border}`, background: "rgba(15,17,23,0.4)" }}>
              <div className="flex items-center gap-2">
                <BarChart3 size={15} color={C.indigo} />
                <span style={{ fontSize: 13.5, fontWeight: 700, color: C.text.primary }}>Análisis Financiero</span>
              </div>
            </div>

            {/* Two charts side by side */}
            <div className="grid gap-0" style={{ gridTemplateColumns: "1fr 220px", borderBottom: `1px solid ${C.border}` }}>
              {/* Left: vertical bar chart */}
              <div className="p-5" style={{ borderRight: `1px solid ${C.border}` }}>
                <p style={{ fontSize: 11, fontWeight: 600, color: C.text.muted, letterSpacing: "0.08em", marginBottom: 12 }}>
                  COMPARATIVO DE CIFRAS PRINCIPALES
                </p>
                <BalanceOverviewChart />
              </div>

              {/* Right: donut + legend */}
              <div className="p-5 flex flex-col justify-center">
                <p style={{ fontSize: 11, fontWeight: 600, color: C.text.muted, letterSpacing: "0.08em", marginBottom: 12 }}>
                  COMPOSICIÓN DEL ACTIVO
                </p>
                <CompositionDonut />
              </div>
            </div>

            {/* Ratio cards */}
            <div className="grid gap-3 p-4" style={{ gridTemplateColumns: "1fr 1fr 1fr" }}>
              {ratioCards.map(card => <RatioCard key={card.label} {...card} />)}
            </div>
          </motion.div>

          {/* ── Aging ── */}
          <AgingSection />

          {/* Footer */}
          <div className="flex items-center gap-2 mt-4 px-1" style={{ fontSize: 11, color: C.text.muted }}>
            <Info size={11} />
            <span>Cifras expresadas en pesos mexicanos (MXN). Período cerrado al 26 de mayo de 2026. Sujeto a revisión de auditoría.</span>
          </div>
        </div>
      </main>
    </div>
  );
}
