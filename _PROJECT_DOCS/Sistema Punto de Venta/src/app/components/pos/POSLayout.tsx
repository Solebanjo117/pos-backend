import { CashRegisterStatus } from "./CashRegisterStatus";
import { POSControls } from "./POSControls";
import { ProductTable } from "./ProductTable";
import { SummarySection } from "./SummarySection";
import { ActionButtons } from "./ActionButtons";

export default function POSLayout() {
  return (
    <div 
      className="h-screen flex flex-col bg-[#F8F9FB] text-gray-900 overflow-hidden"
      style={{ fontFamily: "'Inter', sans-serif" }}
    >
      {/* Header - Fixed Height */}
      <div className="shrink-0 z-20 shadow-sm relative">
        <CashRegisterStatus />
      </div>
      
      {/* Main Content - Flex to fill remaining height */}
      <main className="flex-1 min-h-0 flex flex-col md:flex-row gap-4 p-4 lg:p-6 overflow-hidden">
        
        {/* Left Column: Controls & Product List (Grows to fill space) */}
        <div className="flex-[3] flex flex-col gap-4 min-w-0 h-full overflow-hidden">
          <div className="shrink-0">
            <POSControls />
          </div>
          <div className="flex-1 min-h-0 relative">
            <ProductTable />
          </div>
        </div>
        
        {/* Right Column: Summary & Actions (Fixed width on desktop, flexible on smaller screens) */}
        <div className="flex-1 min-w-[300px] max-w-sm bg-white p-4 rounded-2xl shadow-sm border border-gray-100 flex flex-col h-full overflow-y-auto shrink-0">
          <div className="shrink-0 mb-4">
            <SummarySection />
          </div>
          <div className="mt-auto shrink-0 pt-4">
            <ActionButtons />
          </div>
        </div>
        
      </main>
    </div>
  );
}
