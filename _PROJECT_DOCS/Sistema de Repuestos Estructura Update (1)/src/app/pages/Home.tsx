import { useNavigate } from "react-router";
import { Search, Car, Grid3x3, Code } from "lucide-react";
import { useState } from "react";
import { Header } from "../components/Header";

export default function Home() {
  const navigate = useNavigate();
  const [searchQuery, setSearchQuery] = useState("");

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    if (searchQuery.trim()) {
      navigate(`/catalog?search=${encodeURIComponent(searchQuery.trim())}`);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Header />
      
      <main className="max-w-7xl mx-auto px-4 py-16">
        {/* Hero Section - Buscador Principal */}
        <div className="text-center mb-16">
          <h1 className="text-5xl font-bold mb-4">
            Encuentra el Repuesto Perfecto
          </h1>
          <p className="text-xl text-gray-600 mb-8">
            Catálogo profesional con miles de piezas compatibles
          </p>

          {/* Buscador Grande Tipo Google */}
          <form onSubmit={handleSearch} className="max-w-3xl mx-auto mb-8">
            <div className="relative">
              <Search className="absolute left-4 top-1/2 -translate-y-1/2 w-6 h-6 text-gray-400" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Buscar por código o pieza..."
                className="w-full pl-14 pr-4 py-5 text-lg border-2 border-gray-300 rounded-2xl focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 shadow-lg"
              />
              <button
                type="submit"
                className="absolute right-2 top-1/2 -translate-y-1/2 px-6 py-2.5 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-colors font-medium"
              >
                Buscar
              </button>
            </div>
          </form>

          {/* Botones Rápidos */}
          <div className="flex justify-center gap-4 flex-wrap">
            <button
              onClick={() => navigate("/catalog")}
              className="flex items-center gap-2 px-6 py-3 bg-white border-2 border-gray-200 rounded-xl hover:border-blue-500 hover:text-blue-600 transition-all font-medium shadow-sm"
            >
              <Code className="w-5 h-5" />
              Buscar por Código
            </button>
            <button
              onClick={() => navigate("/vehicle-selector")}
              className="flex items-center gap-2 px-6 py-3 bg-white border-2 border-gray-200 rounded-xl hover:border-blue-500 hover:text-blue-600 transition-all font-medium shadow-sm"
            >
              <Car className="w-5 h-5" />
              Buscar por Vehículo
            </button>
            <button
              onClick={() => navigate("/catalog")}
              className="flex items-center gap-2 px-6 py-3 bg-white border-2 border-gray-200 rounded-xl hover:border-blue-500 hover:text-blue-600 transition-all font-medium shadow-sm"
            >
              <Grid3x3 className="w-5 h-5" />
              Buscar por Categoría
            </button>
          </div>
        </div>

        {/* Categorías Populares */}
        <div className="mb-16">
          <h2 className="text-2xl font-bold mb-6">Categorías Populares</h2>
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            {[
              { name: "Filtros de Aire", icon: "🔧" },
              { name: "Balineras", icon: "⚙️" },
              { name: "Alternadores", icon: "🔌" },
              { name: "Bujías", icon: "⚡" },
              { name: "Bandas", icon: "🔗" },
              { name: "Frenos", icon: "🛑" },
            ].map((cat, idx) => (
              <button
                key={idx}
                onClick={() => navigate("/catalog")}
                className="bg-white p-6 rounded-lg border border-gray-200 hover:border-blue-500 hover:shadow-md transition-all text-center"
              >
                <div className="text-4xl mb-2">{cat.icon}</div>
                <div className="font-medium text-sm">{cat.name}</div>
              </button>
            ))}
          </div>
        </div>

        {/* Marcas */}
        <div>
          <h2 className="text-2xl font-bold mb-6">Marcas Disponibles</h2>
          <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            {["Toyota", "Honda", "Nissan", "Mazda", "Chevrolet", "Hyundai"].map((marca, idx) => (
              <button
                key={idx}
                onClick={() => navigate("/vehicle-selector")}
                className="bg-white p-4 rounded-lg border border-gray-200 hover:border-blue-500 hover:shadow-md transition-all font-medium text-center"
              >
                {marca}
              </button>
            ))}
          </div>
        </div>
      </main>
    </div>
  );
}
