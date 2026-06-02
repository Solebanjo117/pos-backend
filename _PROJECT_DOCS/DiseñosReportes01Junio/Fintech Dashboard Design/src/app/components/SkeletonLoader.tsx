import { motion } from "motion/react";

function ShimmerBox({ w = "100%", h = 20, rounded = 8 }: { w?: string | number; h?: number; rounded?: number }) {
  return (
    <div
      style={{
        width: w,
        height: h,
        borderRadius: rounded,
        background: "linear-gradient(90deg, #252830 0%, #2e3039 50%, #252830 100%)",
        backgroundSize: "200% 100%",
        animation: "shimmer 1.4s infinite linear",
      }}
    />
  );
}

export function SkeletonLoader() {
  return (
    <>
      <style>{`
        @keyframes shimmer {
          0% { background-position: 200% 0; }
          100% { background-position: -200% 0; }
        }
      `}</style>
      <motion.div
        initial={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 0.4 }}
        className="flex flex-col gap-6"
        style={{ padding: 24, paddingTop: 88 }}
      >
        {/* Header skeleton */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <ShimmerBox w={44} h={44} rounded={12} />
            <div className="flex flex-col gap-2">
              <ShimmerBox w={220} h={24} rounded={6} />
              <ShimmerBox w={320} h={14} rounded={4} />
            </div>
          </div>
          <div className="flex items-center gap-3">
            <ShimmerBox w={200} h={36} rounded={8} />
            <ShimmerBox w={240} h={36} rounded={8} />
          </div>
        </div>

        {/* KPI cards skeleton */}
        <div className="grid gap-4" style={{ gridTemplateColumns: "repeat(4, 1fr)" }}>
          {[0, 1, 2, 3].map((i) => (
            <div
              key={i}
              className="rounded-xl p-5"
              style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039", borderLeft: "4px solid #252830" }}
            >
              <div className="flex items-start justify-between mb-4">
                <div className="flex flex-col gap-2">
                  <ShimmerBox w={120} h={12} rounded={4} />
                  <ShimmerBox w={140} h={26} rounded={6} />
                </div>
                <ShimmerBox w={38} h={38} rounded={10} />
              </div>
              <ShimmerBox w={100} h={12} rounded={4} />
              <div className="mt-3">
                <ShimmerBox w="100%" h={36} rounded={4} />
              </div>
            </div>
          ))}
        </div>

        {/* Charts skeleton */}
        <div className="grid gap-6" style={{ gridTemplateColumns: "2fr 1fr" }}>
          <div className="rounded-xl p-5" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
            <div className="flex items-center justify-between mb-5">
              <div className="flex flex-col gap-2">
                <ShimmerBox w={180} h={16} rounded={4} />
                <ShimmerBox w={120} h={12} rounded={4} />
              </div>
            </div>
            <ShimmerBox w="100%" h={300} rounded={8} />
          </div>
          <div className="rounded-xl p-5" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
            <div className="flex flex-col gap-2 mb-5">
              <ShimmerBox w={160} h={16} rounded={4} />
              <ShimmerBox w={120} h={12} rounded={4} />
            </div>
            <ShimmerBox w="100%" h={220} rounded={8} />
            <div className="flex flex-col gap-3 mt-4">
              {[0, 1, 2, 3].map((i) => (
                <div key={i} className="flex items-center justify-between">
                  <ShimmerBox w={100} h={12} rounded={4} />
                  <ShimmerBox w={60} h={12} rounded={4} />
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Bottom row skeleton */}
        <div className="grid gap-6" style={{ gridTemplateColumns: "1fr 1fr" }}>
          {[0, 1].map((i) => (
            <div key={i} className="rounded-xl p-5" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
              <div className="flex flex-col gap-2 mb-5">
                <ShimmerBox w={180} h={16} rounded={4} />
                <ShimmerBox w={120} h={12} rounded={4} />
              </div>
              <div className="flex flex-col gap-4">
                {[0, 1, 2, 3, 4].map((j) => (
                  <div key={j} className="flex items-center gap-3">
                    <ShimmerBox w={36} h={36} rounded={18} />
                    <div className="flex-1 flex flex-col gap-2">
                      <ShimmerBox w="60%" h={12} rounded={4} />
                      <ShimmerBox w="40%" h={10} rounded={4} />
                    </div>
                    <ShimmerBox w={60} h={16} rounded={4} />
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </motion.div>
    </>
  );
}
