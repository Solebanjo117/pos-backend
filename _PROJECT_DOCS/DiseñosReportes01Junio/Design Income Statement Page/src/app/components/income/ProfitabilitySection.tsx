import { useState } from "react";
import {
  PieChart, Pie, Cell, Tooltip,
  BarChart, Bar, XAxis, YAxis, ResponsiveContainer, LabelList,
  LineChart, Line,
} from "recharts";
import { motion, AnimatePresence } from "motion/react";
import { X, TrendingUp } from "lucide-react";

interface BrandItem {
  name: string;
  revenue: number;
  margin: number;
  color: string;
}

const brandData: BrandItem[] = [
  { name: "Samsung",      revenue: 16922, margin: 34.2, color: "#6366f1" },
  { name: "Apple",        revenue: 12088, margin: 28.7, color: "#8b5cf6" },
  { name: "LG Electronics", revenue: 9670, margin: 22.1, color: "#3b82f6" },
  { name: "Sony",         revenue: 5802,  margin: 15.8, color: "#06b6d4" },
  { name: "Otros",        revenue: 3868,  margin: 8.3,  color: "#334155" },
];

const categoryData: BrandItem[] = [
  { name: "Electrónicos",  revenue: 20547, margin: 42.1, color: "#6366f1" },
  { name: "Ropa y Acces.", revenue: 14505, margin: 35.6, color: "#10b981" },
  { name: "Hogar y Deco.", revenue: 7253,  margin: 28.4, color: "#f59e0b" },
  { name: "Alimentos",     revenue: 4352,  margin: 18.2, color: "#f87171" },
  { name: "Otros",         revenue: 1693,  margin: 15.1, color: "#334155" },
];

const totalRevenue = 48350;

// Monthly trend data per brand/category
const trendsByName: Record<string, Array<{ m: string; v: number }>> = {
  Samsung:      [{ m:"Ene", v:12400 }, { m:"Feb", v:14800 }, { m:"Mar", v:13200 }, { m:"Abr", v:15600 }, { m:"May", v:16922 }],
  Apple:        [{ m:"Ene", v:9800  }, { m:"Feb", v:10200 }, { m:"Mar", v:11100 }, { m:"Abr", v:11500 }, { m:"May", v:12088 }],
  "LG Electronics": [{ m:"Ene", v:8900 }, { m:"Feb", v:9100 }, { m:"Mar", v:8400 }, { m:"Abr", v:9000 }, { m:"May", v:9670 }],
  Sony:         [{ m:"Ene", v:5100 }, { m:"Feb", v:5400 }, { m:"Mar", v:4900 }, { m:"Abr", v:5600 }, { m:"May", v:5802 }],
  Otros:        [{ m:"Ene", v:3100 }, { m:"Feb", v:3300 }, { m:"Mar", v:3500 }, { m:"Abr", v:3700 }, { m:"May", v:3868 }],
  Electrónicos: [{ m:"Ene", v:16200 }, { m:"Feb", v:17800 }, { m:"Mar", v:18200 }, { m:"Abr", v:19400 }, { m:"May", v:20547 }],
  "Ropa y Acces.": [{ m:"Ene", v:11200 }, { m:"Feb", v:12100 }, { m:"Mar", v:12800 }, { m:"Abr", v:13900 }, { m:"May", v:14505 }],
  "Hogar y Deco.": [{ m:"Ene", v:5800 }, { m:"Feb", v:6100 }, { m:"Mar", v:6400 }, { m:"Abr", v:6900 }, { m:"May", v:7253 }],
  Alimentos:    [{ m:"Ene", v:3800 }, { m:"Feb", v:4000 }, { m:"Mar", v:4100 }, { m:"Abr", v:4200 }, { m:"May", v:4352 }],
};

const topProductsByName: Record<string, string[]> = {
  Samsung: ["Galaxy S25 Ultra", "Galaxy Tab S10", "Smart TV QLED 65\""],
  Apple:   ["iPhone 16 Pro", "MacBook Air M4", "AirPods Pro 3"],
  "LG Electronics": ["OLED TV 55\"", "Refrigerador French Door", "Lavadora TwinWash"],
  Sony:    ["WH-1000XM6", "PlayStation 5", "Alpha A7 IV"],
  Otros:   ["Variados", "Sin marca propia", "Importaciones"],
  Electrónicos: ["Smartphones", "Laptops", "Smart TVs"],
  "Ropa y Acces.": ["Ropa Casual", "Calzado Deportivo", "Accesorios"],
  "Hogar y Deco.": ["Electrodomésticos", "Muebles", "Decoración"],
  Alimentos: ["Snacks Premium", "Bebidas", "Orgánicos"],
};

function fmtShort(v: number): string { return `$${(v / 1000).toFixed(1)}k`; }
function fmtAmt(v: number): string {
  return "$" + v.toLocaleString("en-US", { minimumFractionDigits: 2 });
}
function marginColor(m: number): string {
  if (m >= 35) return "#34d399";
  if (m >= 22) return "#6366f1";
  if (m >= 15) return "#f59e0b";
  return "#f87171";
}

// ── Tooltips ─────────────────────────────────────────────────────────────

const DonutTooltip = ({ active, payload }: { active?: boolean; payload?: Array<{ payload: BrandItem }> }) => {
  if (active && payload?.length) {
    const d = payload[0].payload;
    return (
      <div style={{ background: "#171a26", border: "1px solid #252830", borderRadius: 8, padding: "9px 13px", fontSize: 12, fontFamily: "inherit" }}>
        <p style={{ color: "#e2e8f0", fontWeight: 500, marginBottom: 3 }}>{d.name}</p>
        <p style={{ color: "#94a3b8" }}>Ventas: <span style={{ color: "#e2e8f0" }}>{fmtAmt(d.revenue)}</span></p>
        <p style={{ color: "#94a3b8" }}>Margen: <span style={{ color: marginColor(d.margin), fontWeight: 500 }}>{d.margin.toFixed(1)}%</span></p>
      </div>
    );
  }
  return null;
};

const BarTooltip = ({ active, payload, label }: { active?: boolean; payload?: Array<{ payload: BrandItem }>; label?: string }) => {
  if (active && payload?.length) {
    const d = payload[0].payload;
    return (
      <div style={{ background: "#171a26", border: "1px solid #252830", borderRadius: 7, padding: "7px 11px", fontSize: 11.5, fontFamily: "inherit" }}>
        <p style={{ color: "#e2e8f0", fontWeight: 500, marginBottom: 2 }}>{label}</p>
        <p style={{ color: marginColor(d.margin) }}>{d.margin.toFixed(1)}% margen</p>
      </div>
    );
  }
  return null;
};

const TrendTooltip = ({ active, payload }: { active?: boolean; payload?: Array<{ payload: { m: string; v: number } }> }) => {
  if (active && payload?.length) {
    const d = payload[0].payload;
    return (
      <div style={{ background: "#171a26", border: "1px solid #252830", borderRadius: 6, padding: "5px 9px", fontSize: 11, fontFamily: "inherit" }}>
        <span style={{ color: "#94a3b8" }}>{d.m}: </span>
        <span style={{ color: "#6366f1", fontWeight: 500 }}>{fmtShort(d.v)}</span>
      </div>
    );
  }
  return null;
};

// ── Segment detail overlay ────────────────────────────────────────────────

function SegmentOverlay({
  item, onClose,
}: {
  item: BrandItem & { cardType: "brand" | "category" };
  onClose: () => void;
}) {
  const trend = trendsByName[item.name] ?? [];
  const products = topProductsByName[item.name] ?? [];

  return (
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      style={{ position: "fixed", inset: 0, zIndex: 80, display: "flex", alignItems: "center", justifyContent: "center" }}
      onClick={onClose}
    >
      <motion.div
        initial={{ scale: 0.92, opacity: 0, x: 40 }}
        animate={{ scale: 1, opacity: 1, x: 0 }}
        exit={{ scale: 0.95, opacity: 0 }}
        transition={{ duration: 0.22, ease: "easeOut" }}
        onClick={(e) => e.stopPropagation()}
        style={{
          width: 340, background: "#171a26", border: "1px solid #252830",
          borderRadius: 14, overflow: "hidden", fontFamily: "inherit",
          boxShadow: "0 20px 60px rgba(0,0,0,0.6)",
        }}
      >
        {/* Header */}
        <div style={{ padding: "14px 18px", borderBottom: "1px solid #1e2130", display: "flex", alignItems: "center", gap: 10 }}>
          <div style={{ width: 10, height: 10, borderRadius: "50%", background: item.color, flexShrink: 0 }} />
          <span style={{ color: "#e2e8f0", fontSize: 13, fontWeight: 600, flex: 1 }}>{item.name}</span>
          <span style={{ padding: "2px 8px", borderRadius: 4, background: `${marginColor(item.margin)}16`, color: marginColor(item.margin), fontSize: 11, fontWeight: 500 }}>
            {item.margin.toFixed(1)}% margen
          </span>
          <button onClick={onClose} style={{ background: "none", border: "none", color: "#475569", cursor: "pointer", padding: 4, marginLeft: 4 }}>
            <X size={14} />
          </button>
        </div>

        <div style={{ padding: "16px 18px" }}>
          {/* Key metrics */}
          <div style={{ display: "flex", gap: 12, marginBottom: 18 }}>
            <div style={{ flex: 1, background: "#1e2130", borderRadius: 8, padding: "10px 12px" }}>
              <p style={{ color: "#475569", fontSize: 10.5, marginBottom: 3 }}>Ventas Totales</p>
              <p style={{ color: "#e2e8f0", fontSize: 15, fontWeight: 600, fontVariantNumeric: "tabular-nums" }}>{fmtShort(item.revenue)}</p>
            </div>
            <div style={{ flex: 1, background: "#1e2130", borderRadius: 8, padding: "10px 12px" }}>
              <p style={{ color: "#475569", fontSize: 10.5, marginBottom: 3 }}>Part. en Ingresos</p>
              <p style={{ color: "#e2e8f0", fontSize: 15, fontWeight: 600, fontVariantNumeric: "tabular-nums" }}>
                {((item.revenue / totalRevenue) * 100).toFixed(1)}%
              </p>
            </div>
          </div>

          {/* Monthly trend sparkline */}
          {trend.length > 0 && (
            <div style={{ marginBottom: 16 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 6, marginBottom: 8 }}>
                <TrendingUp size={12} style={{ color: "#6366f1" }} />
                <span style={{ color: "#475569", fontSize: 10.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}>
                  Tendencia mensual
                </span>
              </div>
              <ResponsiveContainer width="100%" height={60}>
                <LineChart data={trend} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                  <TrendTooltip />
                  <Tooltip content={<TrendTooltip />} />
                  <Line type="monotone" dataKey="v" stroke={item.color} strokeWidth={2} dot={{ fill: item.color, r: 3, strokeWidth: 0 }} activeDot={{ r: 4 }} animationDuration={600} />
                </LineChart>
              </ResponsiveContainer>
              <div style={{ display: "flex", justifyContent: "space-between", marginTop: 3 }}>
                {trend.map((d) => (
                  <span key={d.m} style={{ color: "#334155", fontSize: 9.5, fontFamily: "inherit" }}>{d.m}</span>
                ))}
              </div>
            </div>
          )}

          {/* Top products */}
          <div>
            <p style={{ color: "#334155", fontSize: 10, fontWeight: 700, letterSpacing: "0.1em", textTransform: "uppercase", marginBottom: 8 }}>
              Productos Principales
            </p>
            {products.map((p, i) => (
              <div key={p} style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 6 }}>
                <span style={{ color: "#334155", fontSize: 11, width: 14, textAlign: "center" }}>{i + 1}.</span>
                <span style={{ color: "#6b7280", fontSize: 12 }}>{p}</span>
              </div>
            ))}
          </div>

          {/* Footer link */}
          <div style={{ marginTop: 14, paddingTop: 14, borderTop: "1px solid #1e2130" }}>
            <button
              onClick={onClose}
              style={{ color: "#6366f1", fontSize: 12, background: "none", border: "none", cursor: "pointer", fontFamily: "inherit", padding: 0 }}
            >
              Ver ventas detalladas →
            </button>
          </div>
        </div>
      </motion.div>
    </motion.div>
  );
}

// ── Donut card ─────────────────────────────────────────────────────────────

function DonutCard({
  title, data, avgMargin, cardType,
  selectedItem, onSelectItem,
}: {
  title: string;
  data: BrandItem[];
  avgMargin: number;
  cardType: "brand" | "category";
  selectedItem: (BrandItem & { cardType: "brand" | "category" }) | null;
  onSelectItem: (item: (BrandItem & { cardType: "brand" | "category" }) | null) => void;
}) {
  const pieData = data.map((d) => ({ ...d, value: d.revenue }));
  const barData = data.map((d) => ({ name: d.name, margin: d.margin, color: d.color }));

  return (
    <div style={{ background: "#0f1117", border: "1px solid #1e2130", borderRadius: 12, overflow: "hidden", flex: 1, minWidth: 0 }}>
      <div style={{ padding: "12px 18px", borderBottom: "1px solid #1e2130", background: "#0d0f1a" }}>
        <span style={{ color: "#e2e8f0", fontSize: 12.5, fontWeight: 600, fontFamily: "inherit" }}>{title}</span>
      </div>

      <div style={{ padding: "18px 18px 0" }}>
        {/* Donut + legend */}
        <div style={{ display: "flex", gap: 16, alignItems: "center" }}>
          {/* Fixed 200×200 donut — no ResponsiveContainer */}
          <div style={{ position: "relative", width: 200, height: 200, flexShrink: 0 }}>
            <PieChart width={200} height={200}>
              <Pie
                data={pieData}
                cx={100} cy={100}
                innerRadius={62} outerRadius={90}
                paddingAngle={2} dataKey="value"
                animationBegin={150} animationDuration={1000}
                strokeWidth={0}
                onClick={(d) => onSelectItem({ ...d, cardType })}
                style={{ cursor: "pointer" }}
              >
                {pieData.map((entry, i) => (
                  <Cell
                    key={i}
                    fill={data[i].color}
                    opacity={selectedItem && selectedItem.name !== entry.name ? 0.4 : 1}
                  />
                ))}
              </Pie>
              <Tooltip content={<DonutTooltip />} />
            </PieChart>
            {/* Center label */}
            <div style={{ position: "absolute", top: 100, left: 100, transform: "translate(-50%,-50%)", textAlign: "center", pointerEvents: "none" }}>
              <div style={{ color: marginColor(avgMargin), fontSize: 18, fontWeight: 700, fontFamily: "inherit", lineHeight: 1.15 }}>
                {avgMargin.toFixed(1)}%
              </div>
              <div style={{ color: "#475569", fontSize: 10, fontFamily: "inherit", marginTop: 2 }}>Margen</div>
            </div>
          </div>

          {/* Legend */}
          <div style={{ flex: 1, display: "flex", flexDirection: "column", gap: 7, minWidth: 0 }}>
            {data.map((item) => {
              const pct = ((item.revenue / totalRevenue) * 100).toFixed(1);
              return (
                <div
                  key={item.name}
                  onClick={() => onSelectItem({ ...item, cardType })}
                  style={{ display: "flex", alignItems: "center", gap: 8, cursor: "pointer", opacity: selectedItem && selectedItem.name !== item.name ? 0.4 : 1, transition: "opacity 0.15s" }}
                >
                  <div style={{ width: 7, height: 7, borderRadius: "50%", background: item.color, flexShrink: 0 }} />
                  <span style={{ color: "#6b7280", fontSize: 11.5, flex: 1, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", fontFamily: "inherit" }}>{item.name}</span>
                  <span style={{ color: "#475569", fontSize: 11, fontVariantNumeric: "tabular-nums", fontFamily: "inherit", flexShrink: 0 }}>{fmtShort(item.revenue)}</span>
                  <span style={{ color: "#334155", fontSize: 11, fontVariantNumeric: "tabular-nums", fontFamily: "inherit", flexShrink: 0, width: 36, textAlign: "right" }}>{pct}%</span>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Margin bar chart */}
      <div style={{ padding: "14px 12px 16px", borderTop: "1px solid #1e2130", marginTop: 16 }}>
        <p style={{ color: "#334155", fontSize: 10, fontWeight: 700, letterSpacing: "0.1em", textTransform: "uppercase", fontFamily: "inherit", marginBottom: 10, paddingLeft: 6 }}>
          Margen por entidad
        </p>
        <ResponsiveContainer width="100%" height={130}>
          <BarChart data={barData} layout="vertical" margin={{ top: 0, right: 40, bottom: 0, left: 0 }} barCategoryGap="28%">
            <XAxis type="number" hide domain={[0, 50]} />
            <YAxis type="category" dataKey="name" width={88} tick={{ fill: "#6b7280", fontSize: 11, fontFamily: "inherit" }} axisLine={false} tickLine={false} />
            <Tooltip content={<BarTooltip />} cursor={{ fill: "rgba(255,255,255,0.03)" }} />
            <Bar dataKey="margin" radius={[0, 3, 3, 0]} maxBarSize={9} background={{ fill: "#141720", radius: 3 }}>
              {barData.map((item, i) => (
                <Cell key={i} fill={item.color} onClick={() => onSelectItem({ ...data[i], cardType })} style={{ cursor: "pointer" }} />
              ))}
              <LabelList dataKey="margin" position="right" formatter={(v: number) => `${v.toFixed(1)}%`} style={{ fill: "#475569", fontSize: 10.5, fontFamily: "inherit" }} />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}

// ── Main export ────────────────────────────────────────────────────────────

export function ProfitabilitySection() {
  const [selectedItem, setSelectedItem] = useState<(BrandItem & { cardType: "brand" | "category" }) | null>(null);

  return (
    <div>
      <div style={{ display: "flex", gap: 16 }}>
        <DonutCard
          title="Rentabilidad por Marca"
          data={brandData}
          avgMargin={26.5}
          cardType="brand"
          selectedItem={selectedItem}
          onSelectItem={setSelectedItem}
        />
        <DonutCard
          title="Rentabilidad por Categoría"
          data={categoryData}
          avgMargin={34.7}
          cardType="category"
          selectedItem={selectedItem}
          onSelectItem={setSelectedItem}
        />
      </div>

      <AnimatePresence>
        {selectedItem && (
          <SegmentOverlay
            key={selectedItem.name}
            item={selectedItem}
            onClose={() => setSelectedItem(null)}
          />
        )}
      </AnimatePresence>
    </div>
  );
}
