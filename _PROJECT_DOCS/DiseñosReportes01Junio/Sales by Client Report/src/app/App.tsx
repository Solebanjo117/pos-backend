import { Sidebar } from "./components/Sidebar";
import { TopBar } from "./components/TopBar";
import { SalesByClientReport } from "./components/SalesByClientReport";

export default function App() {
  return (
    <div style={{ background: "#0f1117", minHeight: "100vh", display: "flex" }}>
      <Sidebar />
      <div style={{ marginLeft: 260, flex: 1, display: "flex", flexDirection: "column", minHeight: "100vh" }}>
        <TopBar
          breadcrumbs={[
            { label: "HermeX POS" },
            { label: "Reportes" },
            { label: "Ventas por Cliente", active: true },
          ]}
        />
        <main style={{ marginTop: 64, padding: 24, flex: 1 }}>
          <SalesByClientReport />
        </main>
      </div>
    </div>
  );
}
