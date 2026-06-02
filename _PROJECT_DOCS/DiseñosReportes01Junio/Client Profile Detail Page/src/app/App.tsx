import { Sidebar } from "./components/Sidebar";
import { TopBar } from "./components/TopBar";
import { ClientHeader } from "./components/ClientHeader";
import { ClientInfoGrid } from "./components/ClientInfoGrid";
import { ClientTabs } from "./components/ClientTabs";
import { ResumenLateral } from "./components/ResumenLateral";

export default function App() {
  return (
    <div
      className="min-h-screen flex"
      style={{ backgroundColor: "#0f1117", fontFamily: "Inter, system-ui, sans-serif" }}
    >
      {/* Sidebar */}
      <Sidebar />

      {/* Main content area */}
      <div style={{ marginLeft: 260, flex: 1, minHeight: "100vh" }}>
        {/* Top bar */}
        <TopBar />

        {/* Page content */}
        <div style={{ marginTop: 64, padding: 24 }}>
          <div className="flex gap-6 items-start">
            {/* Left: main content */}
            <div className="flex-1 min-w-0 space-y-4">
              {/* Section 1: Client Header */}
              <ClientHeader />

              {/* Section 2: Info Grid */}
              <ClientInfoGrid />

              {/* Section 3: Tabs */}
              <div
                className="rounded-xl p-5"
                style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
              >
                <ClientTabs />
              </div>
            </div>

            {/* Right: Summary sidebar */}
            <ResumenLateral />
          </div>
        </div>
      </div>
    </div>
  );
}
