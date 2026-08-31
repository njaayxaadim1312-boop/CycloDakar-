import clsx from 'clsx'

interface LogoProps {
  /** Diamètre du médaillon, en pixels. */
  size?: number
  /** Affiche « CYCLO DAKAR » à côté du médaillon. */
  withWordmark?: boolean
  className?: string
}

/**
 * Médaillon officiel du club (assets/brand/logo-cyclo-dakar.jpg).
 *
 * Le logo source est un JPEG à fond blanc : on le recadre en cercle pour qu'il
 * s'intègre proprement sur fond sombre comme sur fond clair, sans le retoucher.
 */
export function Logo({ size = 40, withWordmark = false, className }: LogoProps) {
  return (
    <span className={clsx('inline-flex items-center gap-2.5', className)}>
      <img
        src="/brand/logo.jpg"
        alt="Cyclo Dakar"
        width={size}
        height={size}
        className="shrink-0 rounded-full bg-white object-cover ring-1 ring-black/10"
        style={{ width: size, height: size }}
      />
      {withWordmark && (
        <span className="flex flex-col leading-none">
          <span className="font-display text-[0.95rem] font-extrabold tracking-tight">
            CYCLO <span className="text-brand-text">DAKAR</span>
          </span>
          <span className="text-[0.6875rem] text-[var(--cd-text-muted)]">
            Ensemble, plus loin, plus forts
          </span>
        </span>
      )}
    </span>
  )
}
