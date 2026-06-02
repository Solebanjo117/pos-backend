import { motion, AnimatePresence } from "motion/react";
import {
  Users, Receipt, Calculator, UserCheck, Download, FileSpreadsheet,
  Search, ChevronDown, Wrench, Car, Package, ChevronRight, ChevronLeft,
  Filter, TrendingUp, TrendingDown, Award, BarChart2,
} from "lucide-react";
import { useState, useRef, useEffect } from "react";
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer,
  AreaChart, Area,
} from "recharts";

// ─── Design tokens ────────────────────────────────────────────────────────────
const C = {
  bg:       "#0f1117",
  card:     "#1a1d27",
  muted:    "#252830",
  border:   "#2e3039",
  borderSub:"rgba(255,255,255,0.05)",
  indigo:   "#6366f1",
  violet:   "#8b5cf6",
  emerald:  "#22c55e",
  red:      "#ef4444",
  amber:    "#f59e0b",
  blue:     "#3b82f6",
  text:     "#f9fafb",
  textSub:  "#9ca3af",
  textMuted:"#6b7280",
};

// ─── Types ────────────────────────────────────────────────────────────────────
interface InvoiceRow  { id: string; date: string; amount: string; paid: string; pending: string; method: string }
interface PurchasedItem{ name: string; price: string; qty: number }
interface Vehicle      { name: string; vin: string; year?: string }
interface Client {
  id: number; initials: string; color: string; name: string; mechanic: string;
  invoices: number; total: string; lastPaymentAmount: string; lastPaymentDate: string;
  method: string; status: "aldia"|"pendiente"|"vencido"; pendingAmount?: string;
  invoiceHistory: InvoiceRow[]; purchasedItems: PurchasedItem[]; vehicles: Vehicle[];
}

// ─── Data ─────────────────────────────────────────────────────────────────────
const clients: Client[] = [
  {
    id:1, initials:"MR", color:"#6366f1", name:"Mecánico Rodríguez", mechanic:"—",
    invoices:12, total:"$4,200.00", lastPaymentAmount:"$800.00", lastPaymentDate:"24 May",
    method:"Efectivo", status:"aldia",
    invoiceHistory:[
      { id:"FACT-222104", date:"22 May", amount:"$135.00", paid:"$100.00", pending:"$35.00",  method:"Crédito"   },
      { id:"FACT-222105", date:"24 May", amount:"$800.00", paid:"$800.00", pending:"$0.00",   method:"Efectivo"  },
      { id:"FACT-222098", date:"18 May", amount:"$420.00", paid:"$420.00", pending:"$0.00",   method:"Transfer"  },
    ],
    purchasedItems:[
      { name:"Filtro de aceite Toyota", price:"$12.00", qty:3 },
      { name:"Pastillas de freno Honda", price:"$45.00", qty:1 },
      { name:"Bujía NGK", price:"$8.50", qty:4 },
    ],
    vehicles:[{ name:"Toyota Yaris", year:"2008", vin:"1234ASDF" }, { name:"Honda CRV", year:"2006", vin:"5678QWER" }],
  },
  {
    id:2, initials:"TH", color:"#f59e0b", name:"Taller Hermanos", mechanic:"Mecánico Rodríguez",
    invoices:8, total:"$3,800.00", lastPaymentAmount:"$500.00", lastPaymentDate:"20 May",
    method:"Crédito", status:"pendiente", pendingAmount:"$1,200",
    invoiceHistory:[
      { id:"FACT-222089", date:"20 May", amount:"$500.00", paid:"$500.00", pending:"$0.00",    method:"Crédito" },
      { id:"FACT-222081", date:"15 May", amount:"$700.00", paid:"$0.00",   pending:"$700.00",  method:"Crédito" },
      { id:"FACT-222070", date:"10 May", amount:"$500.00", paid:"$0.00",   pending:"$500.00",  method:"Crédito" },
    ],
    purchasedItems:[
      { name:"Aceite Motor 5W-30",       price:"$18.00", qty:6 },
      { name:"Filtro de aire",           price:"$22.00", qty:2 },
      { name:"Correa de distribución",   price:"$85.00", qty:1 },
    ],
    vehicles:[{ name:"Nissan Sentra", year:"2010", vin:"9012MNOP" }, { name:"Toyota Corolla", year:"2015", vin:"3456BCDE" }],
  },
  {
    id:3, initials:"AP", color:"#22c55e", name:"AutoParts Panamá", mechanic:"Mecánico López",
    invoices:6, total:"$2,950.00", lastPaymentAmount:"$2,950.00", lastPaymentDate:"22 May",
    method:"Transfer", status:"aldia",
    invoiceHistory:[
      { id:"FACT-222099", date:"22 May", amount:"$2,950.00", paid:"$2,950.00", pending:"$0.00", method:"Transfer" },
    ],
    purchasedItems:[
      { name:"Kit de embrague",           price:"$180.00", qty:1 },
      { name:"Amortiguadores Delanteros", price:"$95.00",  qty:2 },
      { name:"Pastillas de freno",        price:"$45.00",  qty:4 },
    ],
    vehicles:[{ name:"Hyundai Tucson", year:"2018", vin:"7890FGHI" }],
  },
  {
    id:4, initials:"CF", color:"#8b5cf6", name:"Cliente Frecuente #12", mechanic:"Mecánico Rodríguez",
    invoices:4, total:"$2,100.00", lastPaymentAmount:"$600.00", lastPaymentDate:"18 May",
    method:"Yappy", status:"pendiente", pendingAmount:"$400",
    invoiceHistory:[
      { id:"FACT-222075", date:"18 May", amount:"$600.00", paid:"$600.00", pending:"$0.00",   method:"Yappy"   },
      { id:"FACT-222060", date:"12 May", amount:"$400.00", paid:"$0.00",   pending:"$400.00", method:"Crédito" },
    ],
    purchasedItems:[
      { name:"Filtro de aceite Toyota",  price:"$12.00", qty:2 },
      { name:"Líquido de frenos DOT4",   price:"$15.00", qty:3 },
    ],
    vehicles:[{ name:"Toyota RAV4", year:"2012", vin:"ABCD1234" }, { name:"Kia Sportage", year:"2019", vin:"EFGH5678" }],
  },
  {
    id:5, initials:"GA", color:"#ef4444", name:"Grúas Atlántico", mechanic:"Mecánico García",
    invoices:3, total:"$1,850.00", lastPaymentAmount:"$300.00", lastPaymentDate:"10 May",
    method:"Transfer", status:"vencido", pendingAmount:"$950",
    invoiceHistory:[
      { id:"FACT-222045", date:"10 May", amount:"$900.00", paid:"$300.00", pending:"$600.00", method:"Transfer" },
      { id:"FACT-222030", date:"02 May", amount:"$950.00", paid:"$600.00", pending:"$350.00", method:"Crédito"  },
    ],
    purchasedItems:[
      { name:"Batería 12V 70Ah",  price:"$120.00", qty:2 },
      { name:"Cable de arranque", price:"$25.00",  qty:3 },
    ],
    vehicles:[{ name:"Ford F-150", year:"2014", vin:"IJKL9012" }],
  },
];

const mechanics = [
  { name:"Mecánico Rodríguez", initials:"MR", color:"#6366f1", clients:5, totalNum:8400, total:"$8,400.00", commission:"$420.00", growth: 12 },
  { name:"Mecánico López",     initials:"ML", color:"#22c55e", clients:3, totalNum:5200, total:"$5,200.00", commission:"$260.00", growth:  5 },
  { name:"Mecánico García",    initials:"MG", color:"#f59e0b", clients:2, totalNum:3100, total:"$3,100.00", commission:"$155.00", growth: -4 },
];

const topClients = [
  { name:"Mecánico Rodríguez",  value:8400,  growth:+12 },
  { name:"Taller Hermanos",     value:3800,  growth: -3 },
  { name:"AutoParts Panamá",    value:2950,  growth: +8 },
  { name:"Cliente Frec. #12",   value:2100,  growth: +2 },
  { name:"Grúas Atlántico",     value:1850,  growth: -7 },
  { name:"Repuestos Norte",     value:1620,  growth: +5 },
  { name:"Mecánica Unida",      value:1380,  growth:+14 },
  { name:"AutoCenter Boquete",  value:1150,  growth: +1 },
  { name:"Taller El Ángel",     value:980,   growth: -2 },
  { name:"Cliente #07",         value:720,   growth: +9 },
];

const TOTAL_REVENUE = 48350;

const sparklines: Record<string, { v: number }[]> = {
  "Mecánico Rodríguez": [680,820,760,980,1050,1280,1420].map(v=>({v})),
  "Mecánico López":     [520,490,610,670,720,810,920].map(v=>({v})),
  "Mecánico García":    [380,410,350,430,390,460,510].map(v=>({v})),
};

// ─── Tiny helpers ─────────────────────────────────────────────────────────────
function Avatar({ initials, color, size=32 }: { initials:string; color:string; size?:number }) {
  return (
    <div style={{
      width:size, height:size, borderRadius:"50%", flexShrink:0,
      background:`linear-gradient(135deg, ${color}28, ${color}12)`,
      border:`1px solid ${color}38`,
      display:"flex", alignItems:"center", justifyContent:"center",
      fontSize:size*0.33, fontWeight:700, color, letterSpacing:"0.02em",
    }}>
      {initials}
    </div>
  );
}

function StatusDot({ status, pendingAmount }: { status:Client["status"]; pendingAmount?:string }) {
  const cfg = {
    aldia:    { color:C.emerald, label:"Al día" },
    pendiente:{ color:C.amber,   label:`Pdte. ${pendingAmount??""}` },
    vencido:  { color:C.red,     label:`Vencido ${pendingAmount??""}` },
  }[status];
  return (
    <div style={{ display:"flex", alignItems:"center", gap:6 }}>
      <div style={{ width:6, height:6, borderRadius:"50%", background:cfg.color, flexShrink:0 }} />
      <span style={{ fontSize:12.5, color:C.textSub, fontWeight:500, whiteSpace:"nowrap" }}>{cfg.label}</span>
    </div>
  );
}

function MethodTag({ method }: { method:string }) {
  return (
    <span style={{
      fontSize:11.5, fontWeight:500, color:C.textMuted,
      background:"rgba(255,255,255,0.04)", border:`1px solid ${C.border}`,
      borderRadius:5, padding:"2px 7px", letterSpacing:"0.01em",
    }}>
      {method}
    </span>
  );
}

// ─── Stat cards ───────────────────────────────────────────────────────────────
function StatCard({ title, value, sub, icon, accent, delay }:
  { title:string; value:string; sub?:string; icon:React.ReactNode; accent:string; delay:number }) {
  return (
    <motion.div
      initial={{ opacity:0, y:12 }} animate={{ opacity:1, y:0 }}
      transition={{ duration:0.35, delay, ease:[0.4,0,0.2,1] }}
      style={{
        background:C.card, border:`1px solid ${C.border}`,
        borderLeft:`3px solid ${accent}`, borderRadius:10,
        padding:"16px 18px", display:"flex", alignItems:"center", gap:14,
        boxShadow:"0 1px 4px rgba(0,0,0,0.25)",
      }}
    >
      <div style={{
        width:38, height:38, borderRadius:9, flexShrink:0,
        background:`${accent}14`, border:`1px solid ${accent}28`,
        display:"flex", alignItems:"center", justifyContent:"center",
      }}>
        {icon}
      </div>
      <div>
        <div style={{ fontSize:11, fontWeight:600, color:C.textMuted, textTransform:"uppercase", letterSpacing:"0.06em", marginBottom:3 }}>{title}</div>
        <div style={{ fontSize:20, fontWeight:700, color:C.text, letterSpacing:"-0.025em", fontVariantNumeric:"tabular-nums", lineHeight:1 }}>{value}</div>
        {sub && <div style={{ fontSize:11.5, color:C.textMuted, marginTop:3 }}>{sub}</div>}
      </div>
    </motion.div>
  );
}

// ─── Expanded row panel ────────────────────────────────────────────────────────
function ExpandedPanel({ client }: { client:Client }) {
  return (
    <motion.tr>
      <td colSpan={8} style={{ padding:0 }}>
        <motion.div
          initial={{ opacity:0, height:0 }}
          animate={{ opacity:1, height:"auto" }}
          exit={{ opacity:0, height:0 }}
          transition={{ height:{ duration:0.32, ease:[0.4,0,0.2,1] }, opacity:{ duration:0.2, delay:0.05 } }}
          style={{ overflow:"hidden" }}
        >
          <div style={{ background:"#0b0d14", borderTop:`1px solid ${C.border}`, padding:"20px 24px 20px 20px" }}>

            {/* Top two columns */}
            <div style={{ display:"grid", gridTemplateColumns:"1fr 1fr", gap:16, marginBottom:16 }}>

              {/* Invoice history */}
              <motion.div
                initial={{ opacity:0, y:8 }} animate={{ opacity:1, y:0 }}
                transition={{ delay:0.1, duration:0.25 }}
                style={{ background:C.card, border:`1px solid ${C.border}`, borderRadius:9, overflow:"hidden" }}
              >
                <div style={{ padding:"10px 14px", borderBottom:`1px solid ${C.border}` }}>
                  <span style={{ fontSize:12, fontWeight:600, color:C.text }}>Historial de Facturas</span>
                </div>
                <table style={{ width:"100%", borderCollapse:"collapse" }}>
                  <thead>
                    <tr style={{ background:"#0f1117" }}>
                      {["Factura","Fecha","Monto","Pagado","Pendiente","Método"].map(h=>(
                        <th key={h} style={{ padding:"7px 12px", fontSize:10.5, fontWeight:600, color:C.textMuted, textAlign:"left", letterSpacing:"0.04em", borderBottom:`1px solid ${C.border}` }}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {client.invoiceHistory.map((inv,i)=>(
                      <tr key={i} style={{ borderBottom:`1px solid ${C.borderSub}` }}>
                        <td style={{ padding:"7px 12px", fontSize:12, color:C.indigo, fontWeight:600, fontFamily:"monospace" }}>{inv.id}</td>
                        <td style={{ padding:"7px 12px", fontSize:12, color:C.textSub }}>{inv.date}</td>
                        <td style={{ padding:"7px 12px", fontSize:12, color:C.text, fontWeight:600, fontVariantNumeric:"tabular-nums" }}>{inv.amount}</td>
                        <td style={{ padding:"7px 12px", fontSize:12, color:C.emerald, fontVariantNumeric:"tabular-nums" }}>{inv.paid}</td>
                        <td style={{ padding:"7px 12px", fontSize:12, fontVariantNumeric:"tabular-nums", color:inv.pending==="$0.00"?C.textMuted:C.amber }}>{inv.pending}</td>
                        <td style={{ padding:"7px 12px" }}><MethodTag method={inv.method}/></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </motion.div>

              {/* Purchased items */}
              <motion.div
                initial={{ opacity:0, y:8 }} animate={{ opacity:1, y:0 }}
                transition={{ delay:0.16, duration:0.25 }}
                style={{ background:C.card, border:`1px solid ${C.border}`, borderRadius:9, overflow:"hidden" }}
              >
                <div style={{ padding:"10px 14px", borderBottom:`1px solid ${C.border}` }}>
                  <span style={{ fontSize:12, fontWeight:600, color:C.text }}>Items Comprados</span>
                </div>
                <div style={{ padding:"10px 14px", display:"flex", flexDirection:"column", gap:7 }}>
                  {client.purchasedItems.map((item,i)=>{
                    const lineTotal = (parseFloat(item.price.replace("$",""))*item.qty).toFixed(2);
                    return (
                      <div key={i} style={{ display:"flex", alignItems:"center", gap:10, padding:"8px 10px", background:"rgba(255,255,255,0.025)", border:`1px solid ${C.border}`, borderRadius:7 }}>
                        <div style={{ width:28,height:28,borderRadius:6, background:`${C.indigo}18`, display:"flex", alignItems:"center", justifyContent:"center", flexShrink:0 }}>
                          <Package size={12} style={{ color:C.indigo }}/>
                        </div>
                        <div style={{ flex:1, minWidth:0 }}>
                          <div style={{ fontSize:12.5, fontWeight:500, color:C.text, overflow:"hidden", textOverflow:"ellipsis", whiteSpace:"nowrap" }}>{item.name}</div>
                          <div style={{ fontSize:11, color:C.textMuted }}>{item.price} × {item.qty}</div>
                        </div>
                        <div style={{ fontSize:12.5, fontWeight:700, color:"#a5b4fc", fontVariantNumeric:"tabular-nums" }}>${lineTotal}</div>
                      </div>
                    );
                  })}
                </div>
              </motion.div>
            </div>

            {/* Vehicles */}
            <motion.div
              initial={{ opacity:0, y:8 }} animate={{ opacity:1, y:0 }}
              transition={{ delay:0.22, duration:0.25 }}
            >
              <div style={{ fontSize:11.5, fontWeight:600, color:C.textMuted, textTransform:"uppercase", letterSpacing:"0.06em", marginBottom:8 }}>Vehículos del Cliente</div>
              <div style={{ display:"flex", gap:10, overflowX:"auto", paddingBottom:2 }}>
                {client.vehicles.map((v,i)=>(
                  <motion.div
                    key={i}
                    whileHover={{ y:-2, boxShadow:`0 6px 20px rgba(0,0,0,0.35)` }}
                    transition={{ duration:0.15 }}
                    style={{
                      display:"flex", alignItems:"center", gap:10, minWidth:190, flexShrink:0,
                      background:`linear-gradient(135deg, ${C.card}, #1e2235)`,
                      border:`1px solid ${C.border}`, borderRadius:8, padding:"10px 14px", cursor:"default",
                    }}
                  >
                    <div style={{ width:32,height:32,borderRadius:7, background:`${C.indigo}18`, border:`1px solid ${C.indigo}28`, display:"flex", alignItems:"center", justifyContent:"center", flexShrink:0 }}>
                      <Car size={14} style={{ color:C.indigo }}/>
                    </div>
                    <div>
                      <div style={{ fontSize:12.5, fontWeight:600, color:C.text }}>{v.name} {v.year}</div>
                      <div style={{ fontSize:10.5, color:C.textMuted, fontFamily:"monospace" }}>VIN: {v.vin}</div>
                    </div>
                  </motion.div>
                ))}
              </div>
            </motion.div>
          </div>
        </motion.div>
      </td>
    </motion.tr>
  );
}

// ─── Mechanic analytics card ───────────────────────────────────────────────────
function MechanicCard({ m, rank, delay }: { m:typeof mechanics[0]; rank:number; delay:number }) {
  const isTop = rank === 1;
  const share = (m.totalNum / TOTAL_REVENUE * 100);
  const [filled, setFilled] = useState(false);
  useEffect(() => { const t = setTimeout(() => setFilled(true), delay * 1000 + 100); return () => clearTimeout(t); }, [delay]);

  const gradId = `spark-fill-${m.initials}`;

  return (
    <motion.div
      initial={{ opacity:0, x:12 }} animate={{ opacity:1, x:0 }}
      transition={{ duration:0.35, delay, ease:[0.4,0,0.2,1] }}
      style={{
        background: isTop ? `${C.indigo}08` : C.card,
        border: `1px solid ${isTop ? `${C.indigo}30` : C.border}`,
        borderRadius:10, overflow:"hidden", position:"relative",
        boxShadow: isTop ? "0 0 0 1px rgba(99,102,241,0.12)" : "none",
      }}
    >
      {/* Sparkline sits behind content */}
      <div style={{ position:"absolute", bottom:0, left:0, right:0, height:48, opacity:0.35, pointerEvents:"none" }}>
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={sparklines[m.name]} margin={{ top:0, right:0, bottom:0, left:0 }}>
            <defs>
              <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={m.color} stopOpacity={0.6}/>
                <stop offset="100%" stopColor={m.color} stopOpacity={0}/>
              </linearGradient>
            </defs>
            <Area type="monotone" dataKey="v" stroke={m.color} strokeWidth={1.5}
              fill={`url(#${gradId})`} dot={false}
              isAnimationActive animationDuration={1200}/>
          </AreaChart>
        </ResponsiveContainer>
      </div>

      <div style={{ padding:"14px 16px", position:"relative", zIndex:1 }}>
        {isTop && (
          <div style={{ position:"absolute", top:0, right:12, display:"flex", alignItems:"center", gap:4, background:"rgba(245,158,11,0.12)", border:"1px solid rgba(245,158,11,0.25)", borderRadius:20, padding:"2px 8px", marginTop:12 }}>
            <Award size={10} style={{ color:C.amber }}/>
            <span style={{ fontSize:10, fontWeight:700, color:C.amber }}>TOP</span>
          </div>
        )}

        <div style={{ display:"flex", alignItems:"center", gap:10, marginBottom:10 }}>
          <Avatar initials={m.initials} color={m.color} size={34}/>
          <div>
            <div style={{ fontSize:13, fontWeight:600, color:C.text }}>{m.name}</div>
            <div style={{ fontSize:11, color:C.textMuted }}>{m.clients} clientes · #{rank} ranking</div>
          </div>
        </div>

        <div style={{ display:"flex", justifyContent:"space-between", marginBottom:10 }}>
          <div>
            <div style={{ fontSize:10, color:C.textMuted, marginBottom:1 }}>Facturado</div>
            <div style={{ fontSize:16, fontWeight:700, color:C.text, letterSpacing:"-0.02em", fontVariantNumeric:"tabular-nums" }}>{m.total}</div>
          </div>
          <div style={{ textAlign:"right" }}>
            <div style={{ fontSize:10, color:C.textMuted, marginBottom:1 }}>Comisión</div>
            <div style={{ fontSize:13, fontWeight:600, color:C.emerald, fontVariantNumeric:"tabular-nums" }}>{m.commission}</div>
            <div style={{ display:"flex", alignItems:"center", justifyContent:"flex-end", gap:3, marginTop:2 }}>
              {m.growth > 0
                ? <TrendingUp size={10} style={{ color:C.emerald }}/>
                : <TrendingDown size={10} style={{ color:C.red }}/>}
              <span style={{ fontSize:10, color: m.growth > 0 ? C.emerald : C.red, fontWeight:600 }}>
                {m.growth > 0 ? "+" : ""}{m.growth}%
              </span>
            </div>
          </div>
        </div>

        {/* CSS-transition progress bar — avoids motion % animation bug */}
        <div>
          <div style={{ display:"flex", justifyContent:"space-between", marginBottom:4 }}>
            <span style={{ fontSize:10, color:C.textMuted }}>Participación en ventas</span>
            <span style={{ fontSize:10, fontWeight:600, color:C.textSub }}>{share.toFixed(1)}%</span>
          </div>
          <div style={{ height:3, background:C.muted, borderRadius:2, overflow:"hidden" }}>
            <div style={{
              height:"100%", background:m.color, borderRadius:2,
              width: filled ? `${share}%` : "0%",
              transition:"width 1s ease-out",
            }}/>
          </div>
        </div>
      </div>
    </motion.div>
  );
}

// ─── Custom recharts bar shape with unique gradient per bar ──────────────────
function GradBar(props: any) {
  const { x, y, width, height, index } = props;
  const id = `gb-${index}`;
  const barH = Math.max(0, height - 6);
  return (
    <g>
      <defs>
        <linearGradient id={id} x1="0" y1="0" x2="1" y2="0">
          <stop offset="0%" stopColor="#6366f1" stopOpacity={0.95}/>
          <stop offset="100%" stopColor="#8b5cf6" stopOpacity={0.8}/>
        </linearGradient>
      </defs>
      <rect x={x} y={y + 3} width={Math.max(0, width)} height={barH} rx={3} fill={`url(#${id})`}/>
    </g>
  );
}

// Custom tooltip for the bar chart
function ChartTooltip({ active, payload, label }: any) {
  if (!active || !payload?.length) return null;
  const row = topClients.find(d => d.name === label);
  return (
    <div style={{
      background: "#1a1d27", border: `1px solid ${C.border}`,
      borderRadius: 8, padding: "10px 14px",
      boxShadow: "0 4px 16px rgba(0,0,0,0.4)",
    }}>
      <div style={{ fontSize: 12, fontWeight: 600, color: C.text, marginBottom: 6 }}>{label}</div>
      <div style={{ fontSize: 15, fontWeight: 700, color: "#a5b4fc", fontVariantNumeric: "tabular-nums" }}>
        ${payload[0].value.toLocaleString()}
      </div>
      {row && (
        <div style={{ marginTop: 6, display: "flex", gap: 10 }}>
          <span style={{ fontSize: 11, color: row.growth > 0 ? C.emerald : C.red, display:"flex", alignItems:"center", gap:3 }}>
            {row.growth > 0 ? <TrendingUp size={10}/> : <TrendingDown size={10}/>}
            {row.growth > 0 ? "+" : ""}{row.growth}% vs ant.
          </span>
          <span style={{ fontSize: 11, color: C.textMuted }}>
            {(row.value / TOTAL_REVENUE * 100).toFixed(1)}% del total
          </span>
        </div>
      )}
    </div>
  );
}

// Custom Y-axis tick: rank badge + name
function RankedTick(props: any) {
  const { x, y, payload, index } = props;
  const rankColor = index === 0 ? "#f59e0b" : index < 3 ? "#a5b4fc" : C.textMuted;
  const name = payload.value as string;
  const short = name.length > 17 ? name.slice(0, 16) + "…" : name;
  return (
    <g transform={`translate(${x},${y})`}>
      <text x={-6} y={0} dy={4} textAnchor="end" fontSize={11.5} fill={C.textSub} fontFamily="Inter,sans-serif">
        {short}
      </text>
      <text x={-158} y={0} dy={4} textAnchor="start" fontSize={9.5} fill={rankColor}
        fontWeight={700} fontFamily="Inter,sans-serif">
        #{index + 1}
      </text>
    </g>
  );
}

// Top clients bar chart — recharts BarChart layout="vertical"
function TopClientsChart() {
  return (
    <ResponsiveContainer width="100%" height={310}>
      <BarChart
        data={topClients}
        layout="vertical"
        margin={{ top: 4, right: 56, bottom: 4, left: 164 }}
        barCategoryGap="28%"
      >
        <XAxis
          type="number"
          hide
          domain={[0, "dataMax"]}
        />
        <YAxis
          type="category"
          dataKey="name"
          width={164}
          axisLine={false}
          tickLine={false}
          tick={<RankedTick/>}
        />
        <Tooltip
          content={<ChartTooltip/>}
          cursor={{ fill: "rgba(99,102,241,0.05)", rx: 4 }}
        />
        <Bar
          dataKey="value"
          shape={<GradBar/>}
          isAnimationActive
          animationDuration={900}
          animationEasing="ease-out"
          label={{
            position: "right",
            fontSize: 11,
            fill: C.textMuted,
            fontFamily: "Inter,sans-serif",
            fontVariantNumeric: "tabular-nums",
            formatter: (v: number) => `$${(v / 1000).toFixed(1)}k`,
          }}
        />
      </BarChart>
    </ResponsiveContainer>
  );
}

// ─── Main component ────────────────────────────────────────────────────────────
export function SalesByClientReport() {
  const [expandedRow, setExpandedRow] = useState<number|null>(null);
  const [search,      setSearch]      = useState("");
  const [dateFrom,    setDateFrom]    = useState("2026-05-01");
  const [dateTo,      setDateTo]      = useState("2026-05-27");
  const [preset,      setPreset]      = useState("Mes");
  const [page,        setPage]        = useState(1);
  const [hoveredRow,  setHoveredRow]  = useState<number|null>(null);
  const tableRef = useRef<HTMLDivElement>(null);

  const filtered = clients.filter(c=>c.name.toLowerCase().includes(search.toLowerCase()));

  const inputStyle: React.CSSProperties = {
    background:"rgba(255,255,255,0.04)",
    border:`1px solid ${C.border}`,
    borderRadius:7,
    padding:"6px 11px",
    color:C.text,
    fontSize:12.5,
    outline:"none",
    colorScheme:"dark",
  };

  const labelStyle: React.CSSProperties = {
    fontSize:10.5, color:C.textMuted, fontWeight:600,
    marginBottom:4, display:"block",
    textTransform:"uppercase", letterSpacing:"0.06em",
  };

  const thStyle: React.CSSProperties = {
    padding:"9px 14px", fontSize:10.5, fontWeight:600,
    color:C.textMuted, textAlign:"left",
    background:"#0b0d14",
    borderBottom:`1px solid ${C.border}`,
    textTransform:"uppercase", letterSpacing:"0.05em",
    position:"sticky", top:0, zIndex:2,
    whiteSpace:"nowrap",
  };

  return (
    <div style={{ display:"flex", flexDirection:"column", gap:20 }}>

      {/* ── Header ───────────────────────────────────────────────────── */}
      <motion.div
        initial={{ opacity:0, y:-8 }} animate={{ opacity:1, y:0 }}
        transition={{ duration:0.3, ease:[0.4,0,0.2,1] }}
        style={{ display:"flex", alignItems:"flex-start", justifyContent:"space-between" }}
      >
        <div style={{ display:"flex", alignItems:"center", gap:12 }}>
          <div style={{ width:40,height:40, borderRadius:10, background:`${C.indigo}16`, border:`1px solid ${C.indigo}28`, display:"flex", alignItems:"center", justifyContent:"center" }}>
            <Users size={18} style={{ color:C.indigo }}/>
          </div>
          <div>
            <h1 style={{ fontSize:22, fontWeight:700, color:C.text, letterSpacing:"-0.025em", margin:0, lineHeight:1.2 }}>
              Ventas por Cliente
            </h1>
            <p style={{ fontSize:12.5, color:C.textMuted, margin:"4px 0 0", lineHeight:1 }}>
              Facturación por cliente y mecánico asignado · Referencias cruzadas
            </p>
          </div>
        </div>
        <div style={{ display:"flex", gap:6 }}>
          {[
            { label:"PDF",   icon:<Download size={12}/>,       color:C.red   },
            { label:"Excel", icon:<FileSpreadsheet size={12}/>, color:C.emerald },
          ].map(btn=>(
            <button key={btn.label} style={{
              display:"flex", alignItems:"center", gap:6,
              background:"rgba(255,255,255,0.04)", border:`1px solid ${C.border}`,
              borderRadius:7, padding:"6px 12px",
              color:btn.color, fontSize:12, fontWeight:500, cursor:"pointer",
              letterSpacing:"0.01em",
            }}>
              {btn.icon}{btn.label}
            </button>
          ))}
        </div>
      </motion.div>

      {/* ── Filters ──────────────────────────────────────────────────── */}
      <motion.div
        initial={{ opacity:0, y:8 }} animate={{ opacity:1, y:0 }}
        transition={{ duration:0.3, delay:0.05, ease:[0.4,0,0.2,1] }}
        style={{ background:C.card, border:`1px solid ${C.border}`, borderRadius:10, padding:"14px 18px" }}
      >
        <div style={{ display:"flex", alignItems:"flex-end", gap:10, flexWrap:"wrap" }}>
          {/* Date range */}
          <div>
            <label style={labelStyle}>Desde</label>
            <input type="date" value={dateFrom} onChange={e=>setDateFrom(e.target.value)} style={inputStyle}/>
          </div>
          <div>
            <label style={labelStyle}>Hasta</label>
            <input type="date" value={dateTo} onChange={e=>setDateTo(e.target.value)} style={inputStyle}/>
          </div>

          {/* Segmented presets */}
          <div>
            <label style={labelStyle}>Período</label>
            <div style={{ display:"flex", background:C.muted, borderRadius:7, padding:2, gap:1 }}>
              {["Mes","Trimestre","Año","Personalizado"].map(p=>(
                <button key={p} onClick={()=>setPreset(p)} style={{
                  background:preset===p?"#1a1d27":"transparent",
                  border:"none",
                  borderRadius:5,
                  padding:"5px 10px",
                  color:preset===p?C.text:C.textMuted,
                  fontSize:12, fontWeight:preset===p?600:400,
                  cursor:"pointer",
                  boxShadow:preset===p?`0 1px 3px rgba(0,0,0,0.3), 0 0 0 1px ${C.border}`:"none",
                  transition:"all 0.15s",
                }}>
                  {p}
                </button>
              ))}
            </div>
          </div>

          {/* Dropdowns */}
          {[
            { label:"Cliente",   placeholder:"Todos los clientes"  },
            { label:"Mecánico",  placeholder:"Todos los mecánicos" },
            { label:"Sucursal",  placeholder:"Sucursal Central"    },
          ].map(sel=>(
            <div key={sel.label} style={{ flex:"1 1 130px", minWidth:130 }}>
              <label style={labelStyle}>{sel.label}</label>
              <div style={{ position:"relative" }}>
                <select style={{ ...inputStyle, width:"100%", appearance:"none", paddingRight:26 }}>
                  <option>{sel.placeholder}</option>
                </select>
                <ChevronDown size={12} style={{ position:"absolute", right:8, top:"50%", transform:"translateY(-50%)", color:C.textMuted, pointerEvents:"none" }}/>
              </div>
            </div>
          ))}

          <button style={{
            display:"flex", alignItems:"center", gap:6,
            background:C.indigo, border:"none", borderRadius:7,
            padding:"7px 14px", color:"#fff", fontSize:12.5, fontWeight:600,
            cursor:"pointer", alignSelf:"flex-end", letterSpacing:"0.01em",
            boxShadow:"0 2px 8px rgba(99,102,241,0.35)",
          }}>
            <Filter size={12}/>Aplicar
          </button>
        </div>
      </motion.div>

      {/* ── Summary cards ────────────────────────────────────────────── */}
      <div style={{ display:"grid", gridTemplateColumns:"repeat(3,1fr)", gap:14 }}>
        <StatCard delay={0.1} title="Total Facturado" value="$48,350.00" sub="142 facturas"
          icon={<Receipt size={16} style={{ color:C.blue }}/>} accent={C.blue}/>
        <StatCard delay={0.14} title="Ticket Promedio" value="$340.49"
          icon={<Calculator size={16} style={{ color:C.violet }}/>} accent={C.violet}/>
        <StatCard delay={0.18} title="Clientes Activos" value="38" sub="con compras en período"
          icon={<UserCheck size={16} style={{ color:C.emerald }}/>} accent={C.emerald}/>
      </div>

      {/* ── Client table ─────────────────────────────────────────────── */}
      <motion.div
        initial={{ opacity:0, y:14 }} animate={{ opacity:1, y:0 }}
        transition={{ duration:0.35, delay:0.22, ease:[0.4,0,0.2,1] }}
        style={{ background:C.card, border:`1px solid ${C.border}`, borderRadius:10, overflow:"hidden" }}
      >
        {/* Table toolbar */}
        <div style={{ display:"flex", alignItems:"center", justifyContent:"space-between", padding:"12px 18px", borderBottom:`1px solid ${C.border}` }}>
          <div style={{ fontSize:13.5, fontWeight:600, color:C.text }}>Detalle por Cliente</div>
          <div style={{ display:"flex", gap:7, alignItems:"center" }}>
            <div style={{ position:"relative" }}>
              <Search size={12} style={{ position:"absolute", left:9, top:"50%", transform:"translateY(-50%)", color:C.textMuted }}/>
              <input placeholder="Buscar cliente…" value={search} onChange={e=>setSearch(e.target.value)}
                style={{ ...inputStyle, paddingLeft:28, width:190 }}/>
            </div>
            <button style={{
              display:"flex", alignItems:"center", gap:5,
              background:"rgba(255,255,255,0.04)", border:`1px solid ${C.border}`,
              borderRadius:7, padding:"6px 11px", color:C.textSub, fontSize:12, cursor:"pointer",
            }}>
              <Download size={11}/>Exportar
            </button>
          </div>
        </div>

        {/* Table with sticky header */}
        <div ref={tableRef} style={{ maxHeight:480, overflowY:"auto", overflowX:"auto" }}>
          <table style={{ width:"100%", borderCollapse:"collapse" }}>
            <thead>
              <tr>
                <th style={{ ...thStyle, width:36, textAlign:"center" }}>#</th>
                <th style={{ ...thStyle }}>Cliente</th>
                <th style={{ ...thStyle }}>Mecánico</th>
                <th style={{ ...thStyle, textAlign:"center" }}>Facturas</th>
                <th style={{ ...thStyle, textAlign:"right" }}>Total</th>
                <th style={{ ...thStyle, textAlign:"right" }}>Último Pago</th>
                <th style={{ ...thStyle }}>Método</th>
                <th style={{ ...thStyle }}>Estado</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((client,i)=>{
                const isHov = hoveredRow===client.id;
                const isExp = expandedRow===client.id;
                return (
                  <>
                    <motion.tr
                      key={client.id}
                      initial={{ opacity:0 }}
                      animate={{ opacity:1 }}
                      transition={{ delay:0.04*i, duration:0.25 }}
                      onMouseEnter={()=>setHoveredRow(client.id)}
                      onMouseLeave={()=>setHoveredRow(null)}
                      onClick={()=>setExpandedRow(isExp?null:client.id)}
                      style={{
                        borderBottom:`1px solid ${C.borderSub}`,
                        background: isHov||isExp?"rgba(99,102,241,0.035)":"transparent",
                        cursor:"pointer",
                        transition:"background 0.12s",
                        borderLeft:`2px solid ${isExp?C.indigo:"transparent"}`,
                      }}
                    >
                      {/* # */}
                      <td style={{ padding:"11px 14px", textAlign:"center" }}>
                        <span style={{ fontSize:11.5, color:C.textMuted, fontVariantNumeric:"tabular-nums" }}>{i+1}</span>
                      </td>
                      {/* Client */}
                      <td style={{ padding:"11px 14px" }}>
                        <div style={{ display:"flex", alignItems:"center", gap:10 }}>
                          <Avatar initials={client.initials} color={client.color}/>
                          <div>
                            <div style={{ fontSize:13, fontWeight:600, color:C.text, letterSpacing:"-0.01em" }}>{client.name}</div>
                          </div>
                          <ChevronRight size={12} style={{ color:C.textMuted, marginLeft:2, transform:isExp?"rotate(90deg)":"rotate(0deg)", transition:"transform 0.2s" }}/>
                        </div>
                      </td>
                      {/* Mechanic */}
                      <td style={{ padding:"11px 14px" }}>
                        {client.mechanic==="—"
                          ? <span style={{ color:C.border, fontSize:13 }}>—</span>
                          : <div style={{ display:"flex", alignItems:"center", gap:5 }}>
                              <Wrench size={11} style={{ color:C.textMuted, flexShrink:0 }}/>
                              <span style={{ fontSize:12, color:C.textSub }}>{client.mechanic}</span>
                            </div>
                        }
                      </td>
                      {/* Invoices */}
                      <td style={{ padding:"11px 14px", textAlign:"center" }}>
                        <span style={{ fontSize:13, fontWeight:600, color:C.text, fontVariantNumeric:"tabular-nums" }}>{client.invoices}</span>
                      </td>
                      {/* Total */}
                      <td style={{ padding:"11px 14px", textAlign:"right" }}>
                        <span style={{ fontSize:13.5, fontWeight:700, color:C.text, fontVariantNumeric:"tabular-nums", letterSpacing:"-0.01em" }}>{client.total}</span>
                      </td>
                      {/* Last payment */}
                      <td style={{ padding:"11px 14px", textAlign:"right" }}>
                        <div style={{ fontSize:13, fontWeight:600, color:C.emerald, fontVariantNumeric:"tabular-nums" }}>{client.lastPaymentAmount}</div>
                        <div style={{ fontSize:10.5, color:C.textMuted }}>{client.lastPaymentDate}</div>
                      </td>
                      {/* Method */}
                      <td style={{ padding:"11px 14px" }}>
                        <MethodTag method={client.method}/>
                      </td>
                      {/* Status */}
                      <td style={{ padding:"11px 14px" }}>
                        <StatusDot status={client.status} pendingAmount={client.pendingAmount}/>
                      </td>
                    </motion.tr>

                    <AnimatePresence>
                      {isExp && <ExpandedPanel key={`ep-${client.id}`} client={client}/>}
                    </AnimatePresence>
                  </>
                );
              })}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        <div style={{ display:"flex", alignItems:"center", justifyContent:"space-between", padding:"10px 18px", borderTop:`1px solid ${C.border}` }}>
          <span style={{ fontSize:12, color:C.textMuted }}>Mostrando 1–10 de 38 clientes</span>
          <div style={{ display:"flex", gap:3 }}>
            {[
              { icon:<ChevronLeft size={13}/>, action:()=>setPage(p=>Math.max(1,p-1)) },
            ].map((btn,i)=>(
              <button key={i} onClick={btn.action} style={{ width:28,height:28, borderRadius:6, display:"flex", alignItems:"center", justifyContent:"center", background:"rgba(255,255,255,0.04)", border:`1px solid ${C.border}`, color:C.textSub, cursor:"pointer" }}>
                {btn.icon}
              </button>
            ))}
            {[1,2,3,4].map(n=>(
              <button key={n} onClick={()=>setPage(n)} style={{ width:28,height:28, borderRadius:6, background:page===n?C.indigo:"rgba(255,255,255,0.04)", border:`1px solid ${page===n?C.indigo:C.border}`, color:page===n?"#fff":C.textSub, fontSize:12, fontWeight:page===n?600:400, cursor:"pointer" }}>
                {n}
              </button>
            ))}
            <button onClick={()=>setPage(p=>Math.min(4,p+1))} style={{ width:28,height:28, borderRadius:6, display:"flex", alignItems:"center", justifyContent:"center", background:"rgba(255,255,255,0.04)", border:`1px solid ${C.border}`, color:C.textSub, cursor:"pointer" }}>
              <ChevronRight size={13}/>
            </button>
          </div>
        </div>
      </motion.div>

      {/* ── Bottom row: mechanic cards + chart ───────────────────────── */}
      <div style={{ display:"grid", gridTemplateColumns:"340px 1fr", gap:16 }}>

        {/* Mechanic analytics */}
        <div style={{ display:"flex", flexDirection:"column", gap:10 }}>
          <div style={{ display:"flex", alignItems:"center", gap:8, marginBottom:2 }}>
            <Wrench size={14} style={{ color:C.violet }}/>
            <span style={{ fontSize:13, fontWeight:600, color:C.text }}>Resumen por Mecánico</span>
          </div>
          {mechanics.map((m,i)=>(
            <MechanicCard key={m.name} m={m} rank={i+1} delay={0.3+i*0.07}/>
          ))}
        </div>

        {/* Top clients chart */}
        <motion.div
          initial={{ opacity:0, y:14 }} animate={{ opacity:1, y:0 }}
          transition={{ duration:0.35, delay:0.32, ease:[0.4,0,0.2,1] }}
          style={{ background:C.card, border:`1px solid ${C.border}`, borderRadius:10, overflow:"hidden" }}
        >
          <div style={{ padding:"14px 18px", borderBottom:`1px solid ${C.border}`, display:"flex", alignItems:"center", gap:8 }}>
            <div style={{ width:26,height:26, borderRadius:6, background:`${C.indigo}16`, display:"flex", alignItems:"center", justifyContent:"center" }}>
              <BarChart2 size={13} style={{ color:C.indigo }}/>
            </div>
            <span style={{ fontSize:13, fontWeight:600, color:C.text }}>Top 10 Clientes por Facturación</span>
            <div style={{ marginLeft:"auto", display:"flex", gap:12, alignItems:"center" }}>
              <div style={{ display:"flex", alignItems:"center", gap:4 }}>
                <div style={{ width:8,height:3, borderRadius:2, background:`linear-gradient(90deg,${C.indigo},${C.violet})` }}/>
                <span style={{ fontSize:10.5, color:C.textMuted }}>Volumen</span>
              </div>
              <div style={{ display:"flex", alignItems:"center", gap:4 }}>
                <TrendingUp size={10} style={{ color:C.emerald }}/>
                <span style={{ fontSize:10.5, color:C.textMuted }}>Crecimiento</span>
              </div>
              <div style={{ fontSize:10.5, color:C.textMuted }}>vs. período ant.</div>
            </div>
          </div>

          <div style={{ padding:"8px 0 12px" }}>
            <TopClientsChart/>
          </div>
        </motion.div>
      </div>
    </div>
  );
}
