import { useState, useMemo } from "react";
import { Search, ChevronUp, ChevronDown, ChevronsUpDown, ArrowUpDown } from "lucide-react";
import { motion } from "motion/react";

export interface Client {
  id: number;
  name: string;
  initials: string;
  avatarColor: string;
  totalBilled: number;
  paid: number;
  pending: number;
  lastPaymentDays: number;
  status: "Corriente" | "Vencido" | "Por Vencer";
  reliability: number;
  invoiceCount: number;
  creditLimit: number;
}

const clients: Client[] = [
  { id: 1, name: "Taller Hermanos García", initials: "TH", avatarColor: "#6366f1", totalBilled: 5000, paid: 3800, pending: 1200, lastPaymentDays: 6, status: "Corriente", reliability: 94, invoiceCount: 8, creditLimit: 6000 },
  { id: 2, name: "Cliente Frecuente #12", initials: "CF", avatarColor: "#8b5cf6", totalBilled: 2500, paid: 2100, pending: 400, lastPaymentDays: 8, status: "Corriente", reliability: 97, invoiceCount: 4, creditLimit: 3000 },
  { id: 3, name: "Servicio Express Panamá", initials: "SE", avatarColor: "#ef4444", totalBilled: 3200, paid: 2400, pending: 800, lastPaymentDays: 95, status: "Vencido", reliability: 42, invoiceCount: 3, creditLimit: 4000 },
  { id: 4, name: "Auto Repuestos JM", initials: "AR", avatarColor: "#f59e0b", totalBilled: 1800, paid: 1200, pending: 600, lastPaymentDays: 42, status: "Por Vencer", reliability: 71, invoiceCount: 5, creditLimit: 2500 },
  { id: 5, name: "Distribuidora Central", initials: "DC", avatarColor: "#22c55e", totalBilled: 4200, paid: 3680, pending: 520, lastPaymentDays: 15, status: "Corriente", reliability: 91, invoiceCount: 11, creditLimit: 5000 },
  { id: 6, name: "Grupo Industrial Norte", initials: "GI", avatarColor: "#3b82f6", totalBilled: 6100, paid: 5600, pending: 500, lastPaymentDays: 22, status: "Corriente", reliability: 88, invoiceCount: 7, creditLimit: 8000 },
  { id: 7, name: "Ferretería Metropolitana", initials: "FM", avatarColor: "#06b6d4", totalBilled: 980, paid: 460, pending: 520, lastPaymentDays: 67, status: "Por Vencer", reliability: 63, invoiceCount: 2, creditLimit: 1500 },
];

const statusCfg = {
  Corriente: { color: "#4ade80", bg: "#22c55e0e", dot: "#22c55e" },
  Vencido: { color: "#f87171", bg: "#ef44440e", dot: "#ef4444" },
  "Por Vencer": { color: "#fbbf24", bg: "#f59e0b0e", dot: "#f59e0b" },
};

const fmt2 = (n: number) =>
  new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

type SortField = "name" | "totalBilled" | "paid" | "pending" | "lastPaymentDays" | "reliability";
type SortDir = "asc" | "desc";

function AgeBar({ days }: { days: number }) {
  const pct = Math.min((days / 120) * 100, 100);
  const color = days <= 30 ? "#22c55e" : days <= 60 ? "#f59e0b" : days <= 90 ? "#f97316" : "#ef4444";
  return (
    <div className="flex items-center gap-2">
      <div className="rounded-full overflow-hidden" style={{ width: 52, height: 4, background: "#1a1d2e" }}>
        <motion.div
          initial={{ width: 0 }}
          animate={{ width: `${pct}%` }}
          transition={{ duration: 0.5, delay: 0.3 }}
          className="h-full rounded-full"
          style={{ background: color }}
        />
      </div>
      <span style={{ color, fontSize: 12, fontVariantNumeric: "tabular-nums", fontWeight: 500 }}>
        {days}d
      </span>
    </div>
  );
}

function ReliabilityDots({ score }: { score: number }) {
  const filled = Math.round(score / 20);
  const color = score >= 85 ? "#22c55e" : score >= 65 ? "#f59e0b" : "#ef4444";
  return (
    <div className="flex items-center gap-2">
      <div className="flex gap-0.5">
        {Array.from({ length: 5 }).map((_, i) => (
          <div
            key={i}
            className="rounded-full"
            style={{
              width: 6,
              height: 6,
              background: i < filled ? color : "#1e2132",
            }}
          />
        ))}
      </div>
      <span style={{ color: "#7880a0", fontSize: 11, fontVariantNumeric: "tabular-nums" }}>{score}%</span>
    </div>
  );
}

interface ClientsTableProps {
  onRegisterPayment: (client: Client) => void;
}

export function ClientsTable({ onRegisterPayment }: ClientsTableProps) {
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("Todos");
  const [sortField, setSortField] = useState<SortField>("pending");
  const [sortDir, setSortDir] = useState<SortDir>("desc");

  function handleSort(field: SortField) {
    if (sortField === field) setSortDir((d) => (d === "asc" ? "desc" : "asc"));
    else { setSortField(field); setSortDir("desc"); }
  }

  const data = useMemo(() => {
    let filtered = clients.filter((c) => {
      const matchSearch = c.name.toLowerCase().includes(search.toLowerCase());
      const matchStatus = statusFilter === "Todos" || c.status === statusFilter;
      return matchSearch && matchStatus;
    });
    filtered = filtered.sort((a, b) => {
      const va = a[sortField] as number | string;
      const vb = b[sortField] as number | string;
      const cmp = typeof va === "string" ? va.localeCompare(vb as string) : (va as number) - (vb as number);
      return sortDir === "asc" ? cmp : -cmp;
    });
    return filtered;
  }, [search, statusFilter, sortField, sortDir]);

  function SortIcon({ field }: { field: SortField }) {
    if (sortField !== field) return <ChevronsUpDown size={11} style={{ color: "#323654" }} />;
    return sortDir === "asc"
      ? <ChevronUp size={11} style={{ color: "#6366f1" }} />
      : <ChevronDown size={11} style={{ color: "#6366f1" }} />;
  }

  const totalPending = data.reduce((s, c) => s + c.pending, 0);
  const totalBilled = data.reduce((s, c) => s + c.totalBilled, 0);
  const totalPaid = data.reduce((s, c) => s + c.paid, 0);

  const cols: { label: string; field?: SortField; align?: "right" }[] = [
    { label: "Cliente", field: "name" },
    { label: "Facturado", field: "totalBilled", align: "right" },
    { label: "Cobrado", field: "paid", align: "right" },
    { label: "Pendiente", field: "pending", align: "right" },
    { label: "Última Pago", field: "lastPaymentDays" },
    { label: "Confiabilidad", field: "reliability" },
    { label: "Estado" },
    { label: "Acción", align: "right" },
  ];

  return (
    <div className="rounded-lg" style={{ background: "#111420", border: "1px solid #1e2132" }}>
      {/* Toolbar */}
      <div
        className="flex items-center justify-between px-4 py-3"
        style={{ borderBottom: "1px solid #1a1d2e" }}
      >
        <div className="flex items-baseline gap-3">
          <span style={{ color: "#dde0ee", fontSize: 14, fontWeight: 600 }}>Saldos por Cliente</span>
          <span style={{ color: "#434865", fontSize: 11 }}>{data.length} registros</span>
        </div>
        <div className="flex items-center gap-2">
          {/* Search */}
          <div className="relative flex items-center">
            <Search size={13} className="absolute left-2.5" style={{ color: "#434865" }} />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Buscar cliente..."
              style={{
                background: "#0e1019",
                border: "1px solid #1e2132",
                borderRadius: 6,
                color: "#dde0ee",
                paddingLeft: 30,
                paddingRight: 10,
                paddingTop: 6,
                paddingBottom: 6,
                fontSize: 12,
                outline: "none",
                width: 185,
              }}
            />
          </div>
          {/* Filter pills */}
          <div
            className="flex items-center rounded-md overflow-hidden"
            style={{ border: "1px solid #1e2132", background: "#0e1019" }}
          >
            {["Todos", "Corriente", "Por Vencer", "Vencido"].map((f) => (
              <button
                key={f}
                onClick={() => setStatusFilter(f)}
                style={{
                  padding: "5px 10px",
                  fontSize: 11,
                  fontWeight: 500,
                  background: statusFilter === f ? "#6366f1" : "transparent",
                  color: statusFilter === f ? "white" : "#4a506a",
                  borderRight: f !== "Vencido" ? "1px solid #1e2132" : undefined,
                  cursor: "pointer",
                  transition: "background 150ms, color 150ms",
                }}
              >
                {f}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Table */}
      <div style={{ overflowX: "auto" }}>
        <table style={{ width: "100%", borderCollapse: "collapse" }}>
          <thead>
            <tr style={{ background: "#0e1019", position: "sticky", top: 0, zIndex: 1 }}>
              {cols.map((col) => (
                <th
                  key={col.label}
                  onClick={() => col.field && handleSort(col.field)}
                  style={{
                    color: "#434865",
                    fontSize: 10,
                    fontWeight: 600,
                    textTransform: "uppercase",
                    letterSpacing: "0.05em",
                    padding: "9px 12px",
                    textAlign: col.align === "right" ? "right" : "left",
                    borderBottom: "1px solid #1a1d2e",
                    cursor: col.field ? "pointer" : "default",
                    userSelect: "none",
                    whiteSpace: "nowrap",
                  }}
                >
                  <span className="inline-flex items-center gap-1">
                    {col.label}
                    {col.field && <SortIcon field={col.field} />}
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.map((client, i) => {
              const sc = statusCfg[client.status];
              const utilPct = Math.round((client.pending / client.creditLimit) * 100);
              return (
                <motion.tr
                  key={client.id}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: i * 0.04 }}
                  className="group"
                  style={{ borderBottom: "1px solid #0e101900" }}
                >
                  {/* Client */}
                  <td style={{ padding: "10px 12px" }}>
                    <div className="flex items-center gap-2.5">
                      <div
                        className="flex items-center justify-center rounded-md flex-shrink-0"
                        style={{
                          width: 30,
                          height: 30,
                          background: `${client.avatarColor}18`,
                          color: client.avatarColor,
                          fontSize: 11,
                          fontWeight: 700,
                          border: `1px solid ${client.avatarColor}20`,
                        }}
                      >
                        {client.initials}
                      </div>
                      <div>
                        <div style={{ color: "#dde0ee", fontSize: 13, fontWeight: 500 }}>{client.name}</div>
                        <div style={{ color: "#434865", fontSize: 10.5, marginTop: 0.5, fontVariantNumeric: "tabular-nums" }}>
                          {client.invoiceCount} fact. · límite ${(client.creditLimit / 1000).toFixed(0)}K ·{" "}
                          <span style={{ color: utilPct > 80 ? "#f87171" : "#434865" }}>
                            {utilPct}% utilizado
                          </span>
                        </div>
                      </div>
                    </div>
                  </td>
                  {/* Facturado */}
                  <td style={{ padding: "10px 12px", textAlign: "right" }}>
                    <span style={{ color: "#7880a0", fontSize: 12, fontVariantNumeric: "tabular-nums" }}>
                      ${fmt2(client.totalBilled)}
                    </span>
                  </td>
                  {/* Cobrado */}
                  <td style={{ padding: "10px 12px", textAlign: "right" }}>
                    <span style={{ color: "#4ade8080", fontSize: 12, fontVariantNumeric: "tabular-nums" }}>
                      ${fmt2(client.paid)}
                    </span>
                  </td>
                  {/* Pendiente */}
                  <td style={{ padding: "10px 12px", textAlign: "right" }}>
                    <span
                      style={{
                        color: client.status === "Vencido" ? "#f87171" : "#fb923c",
                        fontSize: 13,
                        fontWeight: 600,
                        fontVariantNumeric: "tabular-nums",
                      }}
                    >
                      ${fmt2(client.pending)}
                    </span>
                  </td>
                  {/* Last payment */}
                  <td style={{ padding: "10px 12px" }}>
                    <AgeBar days={client.lastPaymentDays} />
                  </td>
                  {/* Reliability */}
                  <td style={{ padding: "10px 12px" }}>
                    <ReliabilityDots score={client.reliability} />
                  </td>
                  {/* Status */}
                  <td style={{ padding: "10px 12px" }}>
                    <div className="inline-flex items-center gap-1.5 rounded px-2 py-0.5"
                      style={{ background: sc.bg }}>
                      <div className="rounded-full" style={{ width: 5, height: 5, background: sc.dot }} />
                      <span style={{ color: sc.color, fontSize: 11, fontWeight: 600 }}>{client.status}</span>
                    </div>
                  </td>
                  {/* Action */}
                  <td style={{ padding: "10px 12px", textAlign: "right" }}>
                    <button
                      onClick={() => onRegisterPayment(client)}
                      className="rounded-md px-2.5 py-1.5 transition-all hover:opacity-80"
                      style={{
                        background: "#6366f118",
                        color: "#818cf8",
                        fontSize: 11,
                        fontWeight: 600,
                        border: "1px solid #6366f130",
                      }}
                    >
                      Registrar Pago
                    </button>
                  </td>
                </motion.tr>
              );
            })}
          </tbody>
          {/* Footer totals */}
          <tfoot>
            <tr style={{ background: "#0e1019", borderTop: "1px solid #1a1d2e" }}>
              <td style={{ padding: "9px 12px", color: "#434865", fontSize: 11, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.04em" }}>
                {data.length} clientes
              </td>
              <td style={{ padding: "9px 12px", textAlign: "right", color: "#7880a0", fontSize: 12, fontWeight: 600, fontVariantNumeric: "tabular-nums" }}>
                ${fmt2(totalBilled)}
              </td>
              <td style={{ padding: "9px 12px", textAlign: "right", color: "#4ade8080", fontSize: 12, fontWeight: 600, fontVariantNumeric: "tabular-nums" }}>
                ${fmt2(totalPaid)}
              </td>
              <td style={{ padding: "9px 12px", textAlign: "right", color: "#fb923c", fontSize: 13, fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>
                ${fmt2(totalPending)}
              </td>
              <td colSpan={4} />
            </tr>
          </tfoot>
        </table>

        {data.length === 0 && (
          <div className="flex items-center justify-center py-10" style={{ color: "#434865", fontSize: 13 }}>
            No se encontraron registros
          </div>
        )}
      </div>
    </div>
  );
}
