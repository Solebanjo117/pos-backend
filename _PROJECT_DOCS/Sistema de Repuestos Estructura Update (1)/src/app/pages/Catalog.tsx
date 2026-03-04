import { useState, useEffect } from "react";
import { useSearchParams } from "react-router";
import { Header } from "../components/Header";
import { ProductCard } from "../components/ProductCard";
import { Filter } from "lucide-react";
import {
  productoData,
  categoriaData,
  searchProductos,
  getProductosByCategoria,
  getProductosByCarro,
  getCarroById,
  getChasisById,
} from "../data/mockData";
import { Producto } from "../types";

export default function Catalog() {
  const [searchParams] = useSearchParams();
  const [productos, setProductos] = useState<Producto[]>(productoData);
  const [selectedCategoria, setSelectedCategoria] = useState<string>("");
  const [precioMin, setPrecioMin] = useState<string>("");
  const [precioMax, setPrecioMax] = useState<string>("");

  // Obtener parámetros de búsqueda
  const searchQuery = searchParams.get("search") || "";
  const carrosParam = searchParams.get("carros") || "";
  const carroIds = carrosParam ? carrosParam.split(",") : [];

  useEffect(() => {
    let filtered = [...productoData];

    // Filtrar por búsqueda de texto
    if (searchQuery) {
      filtered = searchProductos(searchQuery);
    }

    // Filtrar por vehículos compatibles
    if (carroIds.length > 0) {
      filtered = getProductosByCarro(carroIds);
    }

    // Filtrar por categoría
    if (selectedCategoria) {
      filtered = filtered.filter(p => p.categoriaId === selectedCategoria);
    }

    setProductos(filtered);
  }, [searchQuery, carrosParam, selectedCategoria]);

  // Información del vehículo seleccionado
  const vehiculoInfo = carroIds.length > 0 
    ? carroIds.map(id => {
        const carro = getCarroById(id);
        if (!carro) return null;
        const chasis = getChasisById(carro.chasisId);
        return chasis ? `${chasis.marca} ${chasis.modelo} ${chasis.serie}` : null;
      }).filter(Boolean)
    : [];

  return (
    <div className="min-h-screen bg-gray-50">
      <Header />
      
      <main className="max-w-7xl mx-auto px-4 py-8">
        {/* Header del catálogo */}
        <div className="mb-6">
          <h1 className="text-3xl font-bold mb-2">
            Catálogo de Repuestos
          </h1>
          {searchQuery && (
            <p className="text-gray-600">
              Resultados de búsqueda para: <strong>{searchQuery}</strong>
            </p>
          )}
          {vehiculoInfo.length > 0 && (
            <p className="text-gray-600">
              Repuestos compatibles con: <strong>{vehiculoInfo.join(", ")}</strong>
            </p>
          )}
        </div>

        <div className="flex gap-6">
          {/* Sidebar de Filtros */}
          <aside className="w-64 shrink-0">
            <div className="bg-white rounded-lg border border-gray-200 p-4 sticky top-24">
              <div className="flex items-center gap-2 mb-4 pb-3 border-b border-gray-200">
                <Filter className="w-5 h-5" />
                <h2 className="font-bold">Filtros</h2>
              </div>

              {/* Filtro por Categoría */}
              <div className="mb-6">
                <h3 className="font-medium mb-3 text-sm">Categoría</h3>
                <div className="space-y-2">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="radio"
                      name="categoria"
                      value=""
                      checked={selectedCategoria === ""}
                      onChange={(e) => setSelectedCategoria(e.target.value)}
                      className="w-4 h-4 text-blue-600"
                    />
                    <span className="text-sm">Todas</span>
                  </label>
                  {categoriaData.map((cat) => (
                    <label key={cat.id} className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="radio"
                        name="categoria"
                        value={cat.id}
                        checked={selectedCategoria === cat.id}
                        onChange={(e) => setSelectedCategoria(e.target.value)}
                        className="w-4 h-4 text-blue-600"
                      />
                      <span className="text-sm">{cat.nombre}</span>
                    </label>
                  ))}
                </div>
              </div>

              {/* Filtro por Precio */}
              <div className="mb-6">
                <h3 className="font-medium mb-3 text-sm">Rango de Precio</h3>
                <div className="flex gap-2 items-center">
                  <input
                    type="number"
                    placeholder="Min"
                    value={precioMin}
                    onChange={(e) => setPrecioMin(e.target.value)}
                    className="w-full px-2 py-1.5 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                  <span className="text-gray-400">-</span>
                  <input
                    type="number"
                    placeholder="Max"
                    value={precioMax}
                    onChange={(e) => setPrecioMax(e.target.value)}
                    className="w-full px-2 py-1.5 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>

              {/* Filtro por Disponibilidad */}
              <div>
                <h3 className="font-medium mb-3 text-sm">Disponibilidad</h3>
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="checkbox" className="w-4 h-4 text-blue-600 rounded" />
                  <span className="text-sm">Solo en stock</span>
                </label>
              </div>
            </div>
          </aside>

          {/* Grid de Productos */}
          <div className="flex-1">
            <div className="mb-4 flex items-center justify-between">
              <p className="text-gray-600">
                <strong>{productos.length}</strong> productos encontrados
              </p>
              <select className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option>Más relevantes</option>
                <option>Menor precio</option>
                <option>Mayor precio</option>
                <option>Nombre A-Z</option>
              </select>
            </div>

            {productos.length > 0 ? (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {productos.map((producto) => (
                  <ProductCard key={producto.id} producto={producto} />
                ))}
              </div>
            ) : (
              <div className="bg-white rounded-lg border border-gray-200 p-12 text-center">
                <p className="text-gray-500 text-lg">
                  No se encontraron productos con los filtros seleccionados
                </p>
              </div>
            )}
          </div>
        </div>
      </main>
    </div>
  );
}
