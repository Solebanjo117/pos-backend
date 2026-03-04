import { useParams, Link } from "react-router";
import { Header } from "../components/Header";
import { ArrowLeft, Package, Plus, ShoppingCart } from "lucide-react";
import {
  getProductoById,
  getCategoriaById,
  getItemsByProductoId,
  getCarroById,
  getChasisById,
  getMotorById,
  ItemVenta,
} from "../data/mockData";

export default function ProductDetail() {
  const { id } = useParams<{ id: string }>();
  const producto = id ? getProductoById(id) : null;
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const categoria = producto ? getCategoriaById(producto.categoriaId) : null;
  
  // Obtener items reales
  const realItems = producto ? getItemsByProductoId(producto.id) : [];

  // Generar items genéricos para demostrar el scroll
  const genericItems: ItemVenta[] = Array.from({ length: 14 }).map((_, i) => ({
    id: `gen-${i}`,
    productoId: producto?.id || "temp",
    marca: `GENERIC-${String.fromCharCode(74 + i)}`,
    costo: 3.5 + (i * 0.1),
    precioVenta: 3.8 + (i * 0.2),
    stock: 14 + i,
    proveedor: "Generico Inc"
  }));

  const items = [...realItems, ...genericItems];

  // Atributos extendidos
  const extendedAttributes = producto ? {
    ...producto.atributos,
    "Material": "Fibra sintética",
    "Peso": "0.35 kg",
    "Origen": "Japón",
    "Garantía": "6 meses"
  } : {};

  if (!producto) {
    return (
      <div className="min-h-screen bg-gray-50">
        <Header />
        <main className="max-w-7xl mx-auto px-4 py-16 text-center">
          <h1 className="text-2xl font-bold mb-4">Producto no encontrado</h1>
          <Link to="/catalog" className="text-blue-600 hover:underline">
            Volver al catálogo
          </Link>
        </main>
      </div>
    );
  }

  // Obtener compatibilidades detalladas
  const compatibilidades = producto.aplicaciones.map(carroId => {
    const carro = getCarroById(carroId);
    if (!carro) return null;
    const chasis = getChasisById(carro.chasisId);
    const motor = getMotorById(carro.motorId);
    return { carro, chasis, motor };
  }).filter(Boolean);

  return (
    <div className="min-h-screen bg-gray-50 text-sm flex flex-col">
      <Header />
      
      {/* Contenedor principal */}
      <main className="w-full max-w-[1920px] mx-auto px-4 py-6 flex-grow flex flex-col">
        
        {/* Breadcrumb */}
        <div className="mb-4 shrink-0">
          <Link to="/catalog" className="inline-flex items-center gap-2 text-blue-600 hover:underline text-sm">
            <ArrowLeft className="w-4 h-4" />
            Volver al catálogo
          </Link>
        </div>

        {/* CONTENIDO PRINCIPAL: Grid 2 Columnas */}
        <div className="grid grid-cols-1 md:grid-cols-12 gap-6 mb-6">
          
          {/* 🔹 IZQUIERDA: Info Producto + Specs (col-md-5) */}
          {/* Esta columna define la altura "natural" basada en el contenido */}
          <div className="md:col-span-5 flex flex-col gap-4">
            
            {/* Factura */}
            <div className="flex justify-between items-center px-4 py-3 bg-white border border-gray-200 rounded shadow-sm">
              <span className="text-gray-500 font-medium">Factura N°</span>
              <strong className="text-blue-600 font-bold text-lg">00012345</strong>
            </div>

            {/* Tarjeta de Producto Completa */}
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
              
              {/* Imagen */}
              <div className="flex justify-center mb-6 bg-gray-50 rounded-lg p-4 border border-gray-100">
                {producto.imagen ? (
                  <img 
                    src={producto.imagen} 
                    alt={producto.descripcion} 
                    className="max-h-[250px] object-contain" 
                  />
                ) : (
                  <Package className="w-48 h-48 text-gray-300" />
                )}
              </div>

              {/* Título y Código */}
              <div className="text-center mb-6">
                <small className="text-gray-500 uppercase tracking-wide text-xs">Código de Parte</small>
                <h1 className="text-2xl font-bold text-gray-900 mt-1">{producto.codigo}</h1>
                <p className="text-gray-600 mt-2">{producto.descripcion}</p>
              </div>

              {/* Especificaciones Técnicas (Restauradas) */}
              <div>
                <h6 className="font-bold text-gray-900 border-b border-gray-200 pb-2 mb-3">
                  Especificaciones Técnicas
                </h6>
                <div className="grid grid-cols-2 gap-x-4 gap-y-2">
                  {Object.entries(extendedAttributes).map(([key, value]) => (
                    <div key={key} className="flex justify-between items-center text-sm py-1 border-b border-gray-50 last:border-0">
                      <span className="text-gray-500">{key}</span>
                      <span className="font-medium text-gray-900">{value}</span>
                    </div>
                  ))}
                </div>
              </div>

            </div>
          </div>

          {/* 🔹 DERECHA: Marcas Disponibles (col-md-7) */}
          <div className="md:col-span-7">
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 flex flex-col h-full max-h-[600px]">
              
              <div className="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-lg">
                <h2 className="font-bold text-gray-900 text-lg">Marcas Disponibles</h2>
                <span className="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                  {items.length} opciones
                </span>
              </div>
              
              {/* Lista con Scroll Independiente */}
              {/* Altura controlada para no estirar demasiado la página */}
              <div className="overflow-y-auto p-2 custom-scrollbar flex-1">
                {items.length > 0 ? (
                  <div className="space-y-2">
                    {items.map((item) => (
                      <div key={item.id} className="group flex justify-between items-center p-3 border border-gray-100 rounded-lg hover:border-blue-200 hover:bg-blue-50/50 transition-all">
                        
                        {/* Info Marca */}
                        <div className="flex flex-col">
                          <span className="font-bold text-gray-900 text-base">{item.marca}</span>
                          <span className="text-xs text-gray-500">{item.proveedor}</span>
                        </div>

                        {/* Precio y Stock */}
                        <div className="flex items-center gap-6">
                          <div className="text-right">
                            <div className="text-lg font-bold text-green-600">${item.precioVenta.toFixed(2)}</div>
                            <div className={`text-xs font-medium ${
                              item.stock > 5 ? 'text-green-600' : 'text-orange-500'
                            }`}>
                              {item.stock} disponibles
                            </div>
                          </div>

                          {/* Botón */}
                          <button 
                            disabled={item.stock === 0}
                            className="btn bg-blue-600 text-white hover:bg-blue-700 w-10 h-10 rounded-lg flex items-center justify-center shadow-sm transition-transform active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                            title="Agregar al carrito"
                          >
                            <ShoppingCart className="w-5 h-5" />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="text-center py-12">
                    <Package className="w-12 h-12 text-gray-300 mx-auto mb-3" />
                    <p className="text-gray-500">No hay marcas disponibles.</p>
                  </div>
                )}
              </div>
              
              <div className="p-3 bg-gray-50 border-t border-gray-100 rounded-b-lg text-center text-xs text-gray-500">
                Precios sujetos a cambio sin previo aviso.
              </div>

            </div>
          </div>

        </div>

        {/* 🔹 ABAJO: Vehículos Compatibles */}
        <div className="mt-auto">
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 flex flex-col h-[300px]">
            <div className="p-4 border-b border-gray-100 font-bold text-gray-900 flex items-center gap-2">
              <span>Vehículos Compatibles</span>
              <span className="text-xs font-normal text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">
                {compatibilidades.length} modelos
              </span>
            </div>

            <div className="overflow-auto flex-grow custom-scrollbar">
              <table className="w-full text-xs text-left">
                <thead className="bg-gray-50 text-gray-700 font-semibold sticky top-0 z-10 shadow-sm">
                  <tr>
                    <th className="px-4 py-3 border-b border-gray-200">Marca</th>
                    <th className="px-4 py-3 border-b border-gray-200">Modelo</th>
                    <th className="px-4 py-3 border-b border-gray-200">Serie</th>
                    <th className="px-4 py-3 border-b border-gray-200">Motor</th>
                    <th className="px-4 py-3 border-b border-gray-200">Año</th>
                    <th className="px-4 py-3 border-b border-gray-200">VIN (Chasis)</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 bg-white">
                  {compatibilidades.map((comp, idx) => (
                    <tr key={idx} className="hover:bg-blue-50 transition-colors">
                      <td className="px-4 py-3 font-medium text-gray-900">{comp.chasis?.marca}</td>
                      <td className="px-4 py-3">{comp.chasis?.modelo}</td>
                      <td className="px-4 py-3">{comp.chasis?.serie}</td>
                      <td className="px-4 py-3 font-mono text-gray-600">{comp.motor?.codigo}</td>
                      <td className="px-4 py-3">{comp.chasis?.año}</td>
                      <td className="px-4 py-3 font-mono text-gray-500 break-all">
                        {comp.chasis?.vins?.join(", ") || "-"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </main>
    </div>
  );
}
