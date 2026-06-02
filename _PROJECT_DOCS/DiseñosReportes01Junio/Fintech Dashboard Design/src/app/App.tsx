import { useEffect, useState } from "react";
import { AnimatePresence, motion } from "motion/react";
import { Sidebar, type Page } from "./components/Sidebar";
import { TopBar } from "./components/TopBar";
import { SkeletonLoader } from "./components/SkeletonLoader";
import { DashboardHeader } from "./components/DashboardHeader";
import { KPICards } from "./components/KPICards";
import { RevenueChart } from "./components/RevenueChart";
import { PaymentMethodsChart } from "./components/PaymentMethodsChart";
import { TopClientsChart } from "./components/TopClientsChart";
import { LatestTransactions } from "./components/LatestTransactions";
import { TransactionDrawer, type Transaction } from "./components/TransactionDrawer";
import { PageEstadoResultados } from "./components/PageEstadoResultados";
import { PageBalanceGeneral } from "./components/PageBalanceGeneral";
import { PageVentasPorCliente } from "./components/PageVentasPorCliente";
import { PageCuentasPorCobrar } from "./components/PageCuentasPorCobrar";
import { PageCierreDeCaja } from "./components/PageCierreDeCaja";
import { PagePerfilCliente } from "./components/PagePerfilCliente";

const SIDEBAR_FULL = 260;
const SIDEBAR_COLLAPSED = 64;

function PlaceholderPage({ title }: { title: string }) {
  return (
    <motion.div
      initial={{ opacity: 0, x: 24 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.3 }}
      className="flex items-center justify-center"
      style={{ padding: 24, paddingTop: 120, height: "100vh" }}
    >
      <div className="text-center">
        <div
          className="inline-flex items-center justify-center rounded-2xl mb-4"
          style={{ width: 56, height: 56, backgroundColor: "rgba(99,102,241,0.1)", border: "1px solid rgba(99,102,241,0.2)" }}
        >
          <span style={{ fontSize: 24 }}>🚧</span>
        </div>
        <h2 style={{ color: "#f1f5f9", fontWeight: 700 }}>{title}</h2>
        <p style={{ color: "#475569", fontSize: 13, marginTop: 8 }}>Módulo en desarrollo</p>
      </div>
    </motion.div>
  );
}

function DashboardContent({
  onNavigate,
  comparisonMode,
  onToggleComparison,
  onOpenDrawer,
}: {
  onNavigate: (page: Page) => void;
  comparisonMode: boolean;
  onToggleComparison: () => void;
  onOpenDrawer: (tx: Transaction) => void;
}) {
  return (
    <motion.div
      key="dashboard"
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      transition={{ duration: 0.25 }}
      className="flex flex-col gap-6"
      style={{ padding: 24, paddingTop: 88 }}
    >
      <DashboardHeader comparisonMode={comparisonMode} onToggleComparison={onToggleComparison} />
      <KPICards onNavigate={onNavigate} comparisonMode={comparisonMode} />
      <div className="grid gap-6" style={{ gridTemplateColumns: "2fr 1fr" }}>
        <RevenueChart />
        <PaymentMethodsChart />
      </div>
      <div className="grid gap-6" style={{ gridTemplateColumns: "1fr 1fr" }}>
        <TopClientsChart onNavigate={onNavigate} />
        <LatestTransactions onOpenDrawer={onOpenDrawer} onNavigate={onNavigate} />
      </div>
    </motion.div>
  );
}

export default function App() {
  const [isLoading, setIsLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState<Page>("dashboard");
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [comparisonMode, setComparisonMode] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerTx, setDrawerTx] = useState<Transaction | null>(null);
  const [selectedClient, setSelectedClient] = useState<string>("Mecánico Rodríguez");

  useEffect(() => {
    const t = setTimeout(() => setIsLoading(false), 1500);
    return () => clearTimeout(t);
  }, []);

  const sidebarWidth = sidebarCollapsed ? SIDEBAR_COLLAPSED : SIDEBAR_FULL;

  const handleNavigate = (page: Page) => {
    setCurrentPage(page);
    window.scrollTo({ top: 0 });
  };

  const handleOpenDrawer = (tx: Transaction) => {
    setDrawerTx(tx);
    setDrawerOpen(true);
  };

  const renderPage = () => {
    switch (currentPage) {
      case "dashboard":
        return (
          <DashboardContent
            onNavigate={handleNavigate}
            comparisonMode={comparisonMode}
            onToggleComparison={() => setComparisonMode((v) => !v)}
            onOpenDrawer={handleOpenDrawer}
          />
        );
      case "estado-resultados":
        return <PageEstadoResultados />;
      case "balance-general":
        return <PageBalanceGeneral />;
      case "ventas-por-cliente":
        return <PageVentasPorCliente />;
      case "cuentas-por-cobrar":
        return <PageCuentasPorCobrar />;
      case "cierre-de-caja":
        return <PageCierreDeCaja />;
      case "perfil-cliente" as Page:
        return <PagePerfilCliente clientName={selectedClient} />;
      case "productos":
        return <PlaceholderPage title="Productos" />;
      case "clientes":
        return <PlaceholderPage title="Clientes" />;
      case "ventas":
        return <PlaceholderPage title="Ventas" />;
      case "inventario":
        return <PlaceholderPage title="Inventario" />;
      case "configuracion":
        return <PlaceholderPage title="Configuración" />;
      default:
        return <PlaceholderPage title={currentPage} />;
    }
  };

  return (
    <div
      className="flex min-h-screen"
      style={{ backgroundColor: "#0f1117", fontFamily: "'Inter', system-ui, sans-serif" }}
    >
      <Sidebar
        currentPage={currentPage}
        onNavigate={handleNavigate}
        collapsed={sidebarCollapsed}
        onToggleCollapse={() => setSidebarCollapsed((v) => !v)}
      />

      {/* Main content area */}
      <motion.div
        animate={{ marginLeft: sidebarWidth }}
        transition={{ duration: 0.25, ease: "easeInOut" }}
        style={{ flex: 1, minWidth: 0 }}
      >
        <TopBar
          currentPage={currentPage}
          onNavigate={handleNavigate}
          sidebarWidth={sidebarWidth}
        />

        <main style={{ maxWidth: 1440, position: "relative" }}>
          <AnimatePresence mode="wait">
            {isLoading ? (
              <motion.div key="skeleton" exit={{ opacity: 0 }} transition={{ duration: 0.3 }}>
                <SkeletonLoader />
              </motion.div>
            ) : (
              <motion.div key={currentPage}>
                {renderPage()}
              </motion.div>
            )}
          </AnimatePresence>
        </main>
      </motion.div>

      {/* Transaction drawer */}
      <TransactionDrawer
        open={drawerOpen}
        transaction={drawerTx}
        onClose={() => setDrawerOpen(false)}
      />
    </div>
  );
}
