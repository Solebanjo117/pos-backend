import { useState, useEffect } from "react";

export const fmt = (n: number, dec = 2): string =>
  n.toLocaleString("en-US", {
    minimumFractionDigits: dec,
    maximumFractionDigits: dec,
  });

export const fmtPct = (n: number, dec = 1): string =>
  n.toFixed(dec) + "%";

export const deltaColor = (
  delta: number,
  dir: "higher_better" | "lower_better" | "neutral" = "higher_better"
): string => {
  if (dir === "neutral") return "#a1a1aa";
  if (dir === "higher_better") return delta >= 0 ? "#22c55e" : "#ef4444";
  return delta <= 0 ? "#22c55e" : "#f97316";
};

export const deltaBg = (
  delta: number,
  dir: "higher_better" | "lower_better" | "neutral" = "higher_better"
): string => {
  if (dir === "neutral") return "rgba(161,161,170,0.1)";
  if (dir === "higher_better")
    return delta >= 0 ? "rgba(34,197,94,0.08)" : "rgba(239,68,68,0.08)";
  return delta <= 0 ? "rgba(34,197,94,0.08)" : "rgba(249,115,22,0.08)";
};

export function useCountUp(
  target: number,
  duration = 1000,
  delay = 0
): number {
  const [value, setValue] = useState(0);
  useEffect(() => {
    let rafId: number;
    const timer = setTimeout(() => {
      const start = performance.now();
      const tick = (now: number) => {
        const p = Math.min((now - start) / duration, 1);
        const e = 1 - Math.pow(1 - p, 3);
        setValue(target * e);
        if (p < 1) rafId = requestAnimationFrame(tick);
        else setValue(target);
      };
      rafId = requestAnimationFrame(tick);
    }, delay);
    return () => {
      clearTimeout(timer);
      cancelAnimationFrame(rafId);
    };
  }, [target, duration, delay]);
  return value;
}
