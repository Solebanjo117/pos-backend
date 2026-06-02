import { motion } from "motion/react";
import { Printer } from "lucide-react";
import { fmt } from "./utils";

/* ─── QR Code placeholder ─── */
function QRCode({ size = 72 }: { size?: number }) {
  const N = 17;
  const cell = size / N;

  // Finder pattern helper: returns 1 if position is part of the 7x7 finder
  const finderAt = (r: number, c: number, or: number, oc: number): 0 | 1 => {
    const lr = r - or;
    const lc = c - oc;
    if (lr < 0 || lr > 6 || lc < 0 || lc > 6) return 0;
    if (lr === 0 || lr === 6 || lc === 0 || lc === 6) return 1;
    if (lr >= 2 && lr <= 4 && lc >= 2 && lc <= 4) return 1;
    return 0;
  };

  const grid: (0 | 1)[][] = Array.from({ length: N }, (_, r) =>
    Array.from({ length: N }, (_, c): 0 | 1 => {
      if (finderAt(r, c, 0, 0)) return 1;
      if (finderAt(r, c, 0, N - 7)) return 1;
      if (finderAt(r, c, N - 7, 0)) return 1;
      // Timing pattern
      if (r === 6 && c >= 8 && c < N - 8) return c % 2 === 0 ? 1 : 0;
      if (c === 6 && r >= 8 && r < N - 8) return r % 2 === 0 ? 1 : 0;
      // Pseudo-random data
      return (r * 13 + c * 7 + r * c * 3) % 5 < 2 ? 1 : 0;
    })
  );

  return (
    <svg
      width={size}
      height={size}
      viewBox={`0 0 ${size} ${size}`}
      style={{ display: "block" }}
    >
      {grid.map((row, r) =>
        row.map((v, c) =>
          v ? (
            <rect
              key={`${r}-${c}`}
              x={c * cell}
              y={r * cell}
              width={cell}
              height={cell}
              fill="currentColor"
            />
          ) : null
        )
      )}
    </svg>
  );
}

/* ─── Receipt row ─── */
function RLine({
  left,
  right,
  bold = false,
  indent = false,
  separator = false,
  doubleSep = false,
}: {
  left?: string;
  right?: string;
  bold?: boolean;
  indent?: boolean;
  separator?: boolean;
  doubleSep?: boolean;
}) {
  if (separator) {
    return (
      <div
        style={{
          borderTop: "1px dashed #9ca3af",
          margin: "6px 0",
        }}
      />
    );
  }
  if (doubleSep) {
    return (
      <div style={{ margin: "6px 0" }}>
        <div style={{ borderTop: "2px double #374151", marginBottom: 2 }} />
      </div>
    );
  }
  return (
    <div
      className="flex justify-between items-baseline"
      style={{ paddingLeft: indent ? 10 : 0 }}
    >
      <span style={{ color: bold ? "#111827" : "#374151", fontWeight: bold ? 700 : 400, fontSize: 10.5 }}>
        {left}
      </span>
      {right !== undefined && (
        <span
          style={{
            color: bold ? "#111827" : "#4b5563",
            fontWeight: bold ? 700 : 400,
            fontSize: 10.5,
            fontVariantNumeric: "tabular-nums",
          }}
        >
          {right}
        </span>
      )}
    </div>
  );
}

function RCenter({
  text,
  bold = false,
  size = 10.5,
  color = "#374151",
}: {
  text: string;
  bold?: boolean;
  size?: number;
  color?: string;
}) {
  return (
    <div
      style={{
        textAlign: "center",
        color,
        fontWeight: bold ? 700 : 400,
        fontSize: size,
        lineHeight: 1.4,
      }}
    >
      {text}
    </div>
  );
}

export function PrintPreview({ onPrint }: { onPrint?: () => void }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.38, delay: 0.18 }}
      className="rounded-xl border border-[#2e3039] overflow-hidden"
      style={{ background: "#1a1d27" }}
    >
      {/* Header */}
      <div className="flex items-center justify-between px-5 py-3.5 border-b border-[#2e3039]">
        <div className="flex items-center gap-2.5">
          <div
            className="w-7 h-7 rounded-lg flex items-center justify-center"
            style={{ background: "rgba(99,102,241,0.1)" }}
          >
            <Printer size={14} style={{ color: "#6366f1" }} />
          </div>
          <span
            style={{
              color: "#e2e8f0",
              fontWeight: 600,
              fontSize: 12,
              letterSpacing: "0.05em",
            }}
          >
            VISTA PREVIA DE IMPRESIÓN
          </span>
        </div>
        <button
          onClick={onPrint}
          className="flex items-center gap-2 px-4 py-1.5 rounded-lg transition-colors"
          style={{
            background: "#252830",
            border: "1px solid #2e3039",
            color: "#d1d5db",
            fontSize: 12,
            fontWeight: 500,
          }}
          onMouseEnter={(e) => ((e.currentTarget as HTMLButtonElement).style.background = "#2e3039")}
          onMouseLeave={(e) => ((e.currentTarget as HTMLButtonElement).style.background = "#252830")}
        >
          <Printer size={13} />
          Imprimir
        </button>
      </div>

      {/* Receipt + preview area */}
      <div className="flex justify-center px-5 py-6" style={{ background: "#13151e" }}>
        <motion.div
          initial={{ y: 20, opacity: 0 }}
          animate={{ y: 0, opacity: 1 }}
          transition={{ duration: 0.5, delay: 0.35, ease: "easeOut" }}
          style={{
            width: 310,
            background: "#ffffff",
            borderRadius: 4,
            boxShadow: "0 8px 40px rgba(0,0,0,0.5), 0 2px 8px rgba(0,0,0,0.3)",
            padding: "16px 18px",
            fontFamily: "'Courier New', Courier, monospace",
          }}
        >
          {/* Fiscal header */}
          <RCenter text="HERMEX POS S.A." bold size={12.5} color="#111827" />
          <RCenter text="Sucursal Central — Panamá" size={10} color="#4b5563" />
          <RCenter text="RUC: 155-732-4520 DV: 64" size={10} color="#4b5563" />
          <RCenter text="Tel: +507 6000-0000" size={10} color="#4b5563" />
          <RCenter text="info@hermex.com.pa" size={10} color="#4b5563" />
          <RLine separator />

          <RCenter text="*** Z-REPORT ***" bold size={11.5} color="#111827" />
          <RCenter text="CIERRE DE CAJA DIARIO" size={10} color="#374151" />
          <RLine separator />

          <RLine left="Z-Report #:" right="Z-00042" bold />
          <RLine left="Fecha:" right="26/05/2026" />
          <RLine left="Hora cierre:" right="22:34:07" />
          <RLine left="Cajero:" right="Juan Martínez" />
          <RLine left="Sucursal:" right="Central" />
          <RLine left="CF#:" right="CF-2026-05-00042" />
          <RLine separator />

          <RCenter text="ARQUEO DE EFECTIVO" bold size={10.5} color="#111827" />
          <RLine separator />
          <RLine left="Fondo de Apertura:" right="$200.00" />
          <RLine left="+ Ingresos Efectivo:" right="$3,450.00" />
          <RLine left="− Devoluciones:" right="($120.00)" />
          <RLine left="+ Ingreso Extra:" right="$50.00" />
          <RLine left="− Retiro:" right="($100.00)" />
          <div style={{ borderTop: "1px dashed #9ca3af", marginTop: 4, marginBottom: 4 }} />
          <RLine left="TOTAL ESPERADO:" right="$3,480.00" bold />
          <RLine left="Conteo Físico:" right="$3,480.00" />
          <RLine left="Diferencia:" right="$0.00" />
          <div
            style={{
              textAlign: "center",
              color: "#16a34a",
              fontSize: 10.5,
              fontWeight: 700,
              margin: "4px 0",
            }}
          >
            ✓ CAJA CUADRA
          </div>
          <RLine separator />

          <RCenter text="ESTADO DE RESULTADOS" bold size={10.5} color="#111827" />
          <RLine separator />
          <RLine left="Ventas Brutas:" right="$4,200.00" />
          <RLine left="(−) Devoluciones:" right="($120.00)" indent />
          <RLine left="(−) Descuentos:" right="($45.00)" indent />
          <div style={{ borderTop: "1px dashed #9ca3af", marginTop: 4, marginBottom: 4 }} />
          <RLine left="VENTAS NETAS:" right="$4,035.00" bold />
          <RLine left="(−) COGS:" right="($2,450.00)" indent />
          <div style={{ borderTop: "1px dashed #9ca3af", marginTop: 4, marginBottom: 4 }} />
          <RLine left="UTILIDAD BRUTA:" right="$1,585.00" bold />
          <RLine left="Margen Bruto:" right="39.3%" />
          <RLine left="Transacciones:" right="12" />
          <RLine left="Ticket Promedio:" right="$336.25" />
          <RLine separator />

          <RCenter text="MÉTODOS DE PAGO" bold size={10.5} color="#111827" />
          <RLine separator />
          <RLine left="Efectivo:" right="$2,450.00" />
          <RLine left="Tarjeta / Transfer.:" right="$850.00" />
          <RLine left="Yappy:" right="$600.00" />
          <RLine left="Crédito:" right="$135.00" />
          <div style={{ borderTop: "1px dashed #9ca3af", marginTop: 4, marginBottom: 4 }} />
          <RLine left="TOTAL:" right="$4,035.00" bold />
          <RLine doubleSep />

          {/* QR code */}
          <div className="flex justify-center my-3" style={{ color: "#111827" }}>
            <QRCode size={78} />
          </div>
          <RCenter text="Escanee para verificar" size={9} color="#9ca3af" />
          <RLine separator />

          {/* Signature lines */}
          <div style={{ marginTop: 12 }}>
            <div className="flex justify-between" style={{ fontSize: 10, color: "#374151" }}>
              <span>Cajero:</span>
              <span>__________________</span>
            </div>
            <div
              className="flex justify-between"
              style={{ fontSize: 10, color: "#374151", marginTop: 10 }}
            >
              <span>Supervisor:</span>
              <span>__________________</span>
            </div>
          </div>
          <RLine separator />

          {/* Audit footer */}
          <RCenter text="CONTROL FISCAL / AUDIT TRAIL" size={9} color="#9ca3af" />
          <RCenter text="CF#: CF-2026-05-00042" size={9.5} color="#374151" />
          <RCenter text="Hash: A3F7B9C2...8B2C" size={9} color="#9ca3af" />
          <div style={{ marginTop: 4 }}>
            <RCenter text="Impreso: 26/05/2026 a las 22:34:07" size={9} color="#9ca3af" />
            <RCenter text="Sistema: HermeX POS v2.4.1" size={9} color="#9ca3af" />
          </div>
          <RLine separator />
          <RCenter text="*** FIN DEL Z-REPORT ***" bold size={10} color="#111827" />
          <RCenter text="Gracias por usar HermeX POS" size={9} color="#9ca3af" />
        </motion.div>
      </div>
    </motion.div>
  );
}
