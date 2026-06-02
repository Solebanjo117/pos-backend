import { DollarSign, Calendar, ChevronDown, GitCompare } from "lucide-react";
import { motion } from "motion/react";
import { useState } from "react";

const presets = ["Hoy", "Semana", "Mes", "Trimestre", "Año"];

interface DashboardHeaderProps {
  comparisonMode: boolean;
  onToggleComparison: () => void;
}

export function DashboardHeader({ comparisonMode, onToggleComparison }: DashboardHeaderProps) {
  const [activePreset, setActivePreset] = useState("Mes");

  return (
    <motion.div
      initial={{ opacity: 0, y: -12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.4 }}
      className="flex items-start justify-between"
    >
      {/* Title block */}
      <div className="flex items-center gap-3">
        <div
          className="flex items-center justify-center rounded-xl"
          style={{ width: 44, height: 44, background: "rgba(99,102,241,0.12)", border: "1px solid rgba(99,102,241,0.2)" }}
        >
          <DollarSign size={22} color="#6366f1" />
        </div>
        <div>
          <h1 style={{ color: "#f1f5f9", fontWeight: 700, letterSpacing: "-0.5px", lineHeight: 1.2 }}>
            Resumen Financiero
          </h1>
          <p style={{ color: "#475569", fontSize: 13, marginTop: 3 }}>
            Vista general del estado financiero del negocio
          </p>
        </div>
      </div>

      {/* Controls */}
      <div className="flex items-center gap-2">
        {/* Comparison toggle */}
        <button
          onClick={onToggleComparison}
          className="flex items-center gap-2 rounded-lg px-3 transition-all"
          style={{
            height: 36,
            backgroundColor: comparisonMode ? "rgba(99,102,241,0.15)" : "#1a1d27",
            border: comparisonMode ? "1px solid rgba(99,102,241,0.4)" : "1px solid #2e3039",
            color: comparisonMode ? "#818cf8" : "#64748b",
            fontSize: 12,
            fontWeight: comparisonMode ? 600 : 400,
          }}
        >
          <GitCompare size={14} />
          Comparar
        </button>

        {/* Date range */}
        <button
          className="flex items-center gap-2 rounded-lg px-3 transition-all"
          style={{ height: 36, backgroundColor: "#1a1d27", border: "1px solid #2e3039", color: "#94a3b8" }}
        >
          <Calendar size={13} color="#6366f1" />
          <span style={{ fontSize: 12, color: "#cbd5e1" }}>01 May 2026 — 26 May 2026</span>
          <ChevronDown size={12} color="#475569" />
        </button>

        {/* Preset buttons */}
        <div
          className="flex items-center rounded-lg overflow-hidden"
          style={{ border: "1px solid #2e3039", backgroundColor: "#1a1d27" }}
        >
          {presets.map((p, i) => (
            <button
              key={p}
              onClick={() => setActivePreset(p)}
              className="transition-all"
              style={{
                height: 36,
                paddingLeft: 12,
                paddingRight: 12,
                fontSize: 12,
                fontWeight: p === activePreset ? 600 : 400,
                backgroundColor: p === activePreset ? "#6366f1" : "transparent",
                color: p === activePreset ? "#fff" : "#64748b",
                borderRight: i < presets.length - 1 ? "1px solid #2e3039" : "none",
              }}
            >
              {p}
            </button>
          ))}
        </div>
      </div>
    </motion.div>
  );
}
