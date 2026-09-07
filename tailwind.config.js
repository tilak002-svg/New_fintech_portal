/** @type {import('tailwindcss').Config} */

/** Reads an "R G B" CSS variable so Tailwind's color/40-style opacity modifiers work. */
function withOpacity(varName) {
  return ({ opacityValue }) =>
    opacityValue === undefined
      ? `rgb(var(${varName}))`
      : `rgb(var(${varName}) / ${opacityValue})`;
}

module.exports = {
  content: [
    "./public/**/*.php",
    "./pages/**/*.php",
    "./includes/**/*.php",
    "./public/assets/js/**/*.js"
  ],
  theme: {
    fontFamily: {
      sans: ["Montserrat", "ui-sans-serif", "system-ui", "sans-serif"],
      mono: ["ui-monospace", "SFMono-Regular", "Menlo", "Consolas", "monospace"]
    },
    fontSize: {
      xs: ["0.75rem", "1.1"],
      sm: ["0.765625rem", "1.2"],
      md: ["0.85rem", "1.35"],
      lg: ["0.875rem", "1.4"],
      xl: ["0.9rem", "1.4"],
      "2xl": ["1rem", "1.5"],
      "3xl": ["1.25rem", "1.4"],
      "4xl": ["1.6rem", "1.25"]
    },
    borderRadius: {
      none: "0px",
      sm: "6px",
      DEFAULT: "8px",
      md: "12px",
      lg: "14px",
      xl: "15px",
      full: "50px"
    },
    extend: {
      colors: {
        text: {
          primary: withOpacity("--color-text-primary-rgb"),
          secondary: withOpacity("--color-text-secondary-rgb"),
          tertiary: withOpacity("--color-text-tertiary-rgb"),
          inverse: withOpacity("--color-text-inverse-rgb")
        },
        surface: {
          base: withOpacity("--color-surface-base-rgb"),
          muted: withOpacity("--color-surface-muted-rgb"),
          raised: withOpacity("--color-surface-raised-rgb"),
          strong: withOpacity("--color-surface-strong-rgb")
        },
        border: {
          DEFAULT: withOpacity("--color-border-default-rgb"),
          strong: withOpacity("--color-border-strong-rgb")
        },
        brand: {
          DEFAULT: withOpacity("--color-brand-rgb"),
          emphasis: withOpacity("--color-brand-emphasis-rgb"),
          muted: withOpacity("--color-brand-muted-rgb")
        },
        success: { DEFAULT: withOpacity("--color-success-rgb"), bg: "var(--color-success-bg)" },
        danger: { DEFAULT: withOpacity("--color-danger-rgb"), bg: "var(--color-danger-bg)" },
        warning: { DEFAULT: withOpacity("--color-warning-rgb"), bg: "var(--color-warning-bg)" },
        info: { DEFAULT: withOpacity("--color-info-rgb"), bg: "var(--color-info-bg)" },
        neutral: { DEFAULT: withOpacity("--color-neutral-rgb"), bg: "var(--color-neutral-bg)" }
      },
      boxShadow: {
        card: "rgba(149, 157, 165, 0.2) 0px 8px 24px 0px",
        soft: "rgba(30, 64, 175, 0.05) 0px 4px 12px 0px"
      },
      transitionDuration: {
        instant: "200ms",
        fast: "300ms"
      }
    }
  },
  plugins: []
};
