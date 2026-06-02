import { Sidebar } from "./components/Sidebar";
import { ZReport } from "./components/ZReport";

export default function App() {
  return (
    <div className="flex h-screen bg-[#0f1117] overflow-hidden">
      <Sidebar />
      <ZReport />
    </div>
  );
}
