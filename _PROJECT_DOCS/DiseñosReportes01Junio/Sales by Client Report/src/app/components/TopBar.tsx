import { Bell, ChevronDown, ChevronRight, Store } from "lucide-react";

interface BreadcrumbItem {
  label: string;
  active?: boolean;
}

interface TopBarProps {
  breadcrumbs?: BreadcrumbItem[];
}

export function TopBar({ breadcrumbs = [] }: TopBarProps) {
  return (
    <header
      style={{
        height: 64,
        background: "#0f1117",
        borderBottom: "1px solid #2e3039",
        position: "fixed",
        top: 0,
        left: 260,
        right: 0,
        zIndex: 20,
        display: "flex",
        alignItems: "center",
        justifyContent: "space-between",
        padding: "0 24px",
      }}
    >
      {/* Breadcrumbs */}
      <div className="flex items-center gap-1.5" style={{ fontSize: 13 }}>
        {breadcrumbs.map((crumb, i) => (
          <div key={crumb.label} className="flex items-center gap-1.5">
            {i > 0 && <ChevronRight size={13} style={{ color: "#4b5563" }} />}
            <span
              style={{
                color: crumb.active ? "#e5e7eb" : "#6b7280",
                fontWeight: crumb.active ? 600 : 400,
              }}
            >
              {crumb.label}
            </span>
          </div>
        ))}
      </div>

      {/* Right controls */}
      <div className="flex items-center gap-3">
        {/* Warehouse selector */}
        <button
          style={{
            display: "flex",
            alignItems: "center",
            gap: 8,
            background: "#1a1d27",
            border: "1px solid #2e3039",
            borderRadius: 8,
            padding: "6px 12px",
            color: "#e5e7eb",
            fontSize: 13,
            fontWeight: 500,
            cursor: "pointer",
          }}
        >
          <Store size={14} style={{ color: "#6366f1" }} />
          <span>Sucursal Central</span>
          <ChevronDown size={13} style={{ color: "#6b7280" }} />
        </button>

        {/* Notification bell */}
        <button
          style={{
            position: "relative",
            background: "#1a1d27",
            border: "1px solid #2e3039",
            borderRadius: 8,
            width: 36,
            height: 36,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            cursor: "pointer",
          }}
        >
          <Bell size={15} style={{ color: "#9ca3af" }} />
          <span
            style={{
              position: "absolute",
              top: 7,
              right: 7,
              width: 6,
              height: 6,
              borderRadius: "50%",
              background: "#ef4444",
            }}
          />
        </button>

        {/* User avatar */}
        <div
          style={{
            width: 34,
            height: 34,
            borderRadius: "50%",
            background: "linear-gradient(135deg, #6366f1, #8b5cf6)",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            fontSize: 12,
            fontWeight: 700,
            color: "#fff",
            cursor: "pointer",
          }}
        >
          AD
        </div>
      </div>
    </header>
  );
}
