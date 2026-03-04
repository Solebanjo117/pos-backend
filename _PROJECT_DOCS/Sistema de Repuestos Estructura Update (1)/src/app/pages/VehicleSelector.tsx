import { useState } from "react";
import { useNavigate } from "react-router";
import { Header } from "../components/Header";
import { ChevronDown } from "lucide-react";
import { getMarcas, getModelosByMarca, getSeriesByMarcaModelo, getCarrosByVehiculo } from "../data/mockData";

export default function VehicleSelector() {
  const navigate = useNavigate();
  const [selectedMarca, setSelectedMarca] = useState("");
  const [selectedModelo, setSelectedModelo] = useState("");
  const [selectedSerie, setSelectedSerie] = useState("");

  const marcas = getMarcas();
  const modelos = selectedMarca ? getModelosByMarca(selectedMarca) : [];
  const series = selectedMarca && selectedModelo ? getSeriesByMarcaModelo(selectedMarca, selectedModelo) : [];

  const handleMarcaChange = (marca: string) => {
    setSelectedMarca(marca);
    setSelectedModelo("");
    setSelectedSerie("");
  };

  const handleModeloChange = (modelo: string) => {
    setSelectedModelo(modelo);
    setSelectedSerie("");
  };

  const handleBuscar = () => {
    if (!selectedMarca) {
      alert("Por favor selecciona una marca");
      return;
    }

    const carros = getCarrosByVehiculo(selectedMarca, selectedModelo, selectedSerie);
    const carroIds = carros.map(c => c.id).join(",");
    
    navigate(`/catalog?carros=${carroIds}`);
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Header />
      
      <main className="max-w-4xl mx-auto px-4 py-16">
        <div className="bg-white rounded-2xl shadow-lg p-8 md:p-12">
          <h1 className="text-3xl font-bold mb-2 text-center">
            Selecciona tu Vehículo
          </h1>
          <p className="text-gray-600 mb-8 text-center">
            Encuentra repuestos compatibles con tu auto
          </p>

          <div className="space-y-6">
            {/* Selector de Marca */}
            <div>
              <label className="block text-sm font-medium mb-2">
                Marca <span className="text-red-500">*</span>
              </label>
              <div className="relative">
                <select
                  value={selectedMarca}
                  onChange={(e) => handleMarcaChange(e.target.value)}
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-lg"
                >
                  <option value="">Seleccionar marca...</option>
                  {marcas.map((marca, idx) => (
                    <option key={idx} value={marca}>
                      {marca}
                    </option>
                  ))}
                </select>
                <ChevronDown className="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" />
              </div>
            </div>

            {/* Selector de Modelo */}
            <div>
              <label className="block text-sm font-medium mb-2">
                Modelo {selectedMarca && <span className="text-red-500">*</span>}
              </label>
              <div className="relative">
                <select
                  value={selectedModelo}
                  onChange={(e) => handleModeloChange(e.target.value)}
                  disabled={!selectedMarca}
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-lg disabled:bg-gray-100 disabled:cursor-not-allowed"
                >
                  <option value="">Seleccionar modelo...</option>
                  {modelos.map((modelo, idx) => (
                    <option key={idx} value={modelo}>
                      {modelo}
                    </option>
                  ))}
                </select>
                <ChevronDown className="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" />
              </div>
            </div>

            {/* Selector de Serie/Año */}
            <div>
              <label className="block text-sm font-medium mb-2">
                Serie / Año (Opcional)
              </label>
              <div className="relative">
                <select
                  value={selectedSerie}
                  onChange={(e) => setSelectedSerie(e.target.value)}
                  disabled={!selectedModelo}
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white text-lg disabled:bg-gray-100 disabled:cursor-not-allowed"
                >
                  <option value="">Todas las series...</option>
                  {series.map((chasis) => (
                    <option key={chasis.id} value={chasis.serie}>
                      {chasis.serie} ({chasis.año})
                    </option>
                  ))}
                </select>
                <ChevronDown className="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" />
              </div>
            </div>

            {/* Botón Buscar */}
            <button
              onClick={handleBuscar}
              disabled={!selectedMarca}
              className="w-full py-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium text-lg disabled:bg-gray-300 disabled:cursor-not-allowed mt-8"
            >
              Ver Repuestos Compatibles
            </button>
          </div>

          {/* Info adicional */}
          {selectedMarca && (
            <div className="mt-8 p-4 bg-blue-50 rounded-lg">
              <p className="text-sm text-blue-800">
                <strong>Selección actual:</strong> {selectedMarca}
                {selectedModelo && ` > ${selectedModelo}`}
                {selectedSerie && ` > ${selectedSerie}`}
              </p>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
