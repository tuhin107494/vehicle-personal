/** The full-page poster behind the sign-in card: the app's standard light background with a row of vehicles on a road at the bottom, no text. */
export function LoginPoster() {
  return (
    <div className="pointer-events-none fixed inset-0 flex flex-col justify-end overflow-hidden p-6 sm:p-10">
      <div className="pointer-events-none absolute -right-16 -top-16 size-72 rounded-full bg-emerald-200/40" />
      <div className="pointer-events-none absolute -bottom-28 -left-16 size-80 rounded-full bg-emerald-200/40" />

      <svg viewBox="0 0 520 190" preserveAspectRatio="xMidYMax meet" className="relative mx-auto max-h-[28vh] w-full max-w-2xl" role="img" aria-label="A car, a CNG auto and a motorcycle on a road">
        <rect x="0" y="150" width="520" height="40" rx="6" fill="#a7f3d0" />
        <path d="M10 170h40M80 170h40M150 170h40M220 170h40M290 170h40M360 170h40M430 170h40" stroke="#fff" strokeWidth="3" strokeLinecap="round" />
        {/* car */}
        <g>
          <path d="M20 148v-22c0-6 4-10 10-11l22-5 22-24c3-3 6-4 10-4h46c4 0 7 1 10 4l24 24 24 4c8 2 12 6 12 13v21z" fill="#10b981" />
          <path d="M80 106l9-17h23v17zM118 106V89h18l17 17z" fill="#ecfdf5" />
          <circle cx="54" cy="148" r="17" fill="#064e3b" /><circle cx="54" cy="148" r="7" fill="#a7f3d0" />
          <circle cx="168" cy="148" r="17" fill="#064e3b" /><circle cx="168" cy="148" r="7" fill="#a7f3d0" />
          <rect x="188" y="120" width="10" height="7" rx="3" fill="#fde68a" />
        </g>
        {/* CNG auto */}
        <g>
          <path d="M268 148v-34c0-12 8-20 20-20h36c10 0 16 6 18 14l6 40z" fill="#f59e0b" />
          <path d="M276 96h40c8 0 12 4 14 10l2 8h-56z" fill="#fffbeb" />
          <circle cx="292" cy="148" r="12" fill="#064e3b" /><circle cx="292" cy="148" r="5" fill="#a7f3d0" />
          <circle cx="344" cy="148" r="12" fill="#064e3b" /><circle cx="344" cy="148" r="5" fill="#a7f3d0" />
        </g>
        {/* motorcycle */}
        <g>
          <circle cx="400" cy="140" r="17" fill="none" stroke="#064e3b" strokeWidth="5" />
          <circle cx="470" cy="140" r="17" fill="none" stroke="#064e3b" strokeWidth="5" />
          <path d="M400 140l22-34h28l20 34M422 106l-8-12h-12M450 106l-4-16h14" fill="none" stroke="#059669" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round" />
          <rect x="426" y="96" width="30" height="10" rx="3" fill="#10b981" />
        </g>
      </svg>
    </div>
  );
}
