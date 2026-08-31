import clsx from 'clsx'

interface LogoProps {
  /** Diamètre du médaillon, en pixels. */
  size?: number
  /** Affiche « CYCLO DAKAR » à côté du médaillon. */
  withWordmark?: boolean
  /**
   * `onOrange` adapte les couleurs du texte pour un fond orange plein
   * (en-tête du menu latéral), où l'orange du mot-symbole serait invisible.
   */
  variant?: 'default' | 'onOrange'
  className?: string
}

/**
 * Médaillon officiel du club (assets/brand/logo-cyclo-dakar.jpg).
 *
 * Le logo source est un JPEG à fond blanc : on le recadre en cercle pour qu'il
 * s'intègre proprement sur fond clair, sombre ou orange, sans le retoucher.
 */
export function Logo({
  size = 40,
  withWordmark = false,
  variant = 'default',
  className,
}: LogoProps) {
  const onOrange = variant === 'onOrange'

  return (
    <span className={clsx('inline-flex min-w-0 items-center gap-2.5', className)}>
      <img
        src="/brand/logo.jpg"
        alt="Cyclo Dakar"
        width={size}
        height={size}
        className="shrink-0 rounded-full bg-white object-cover ring-1 ring-black/10"
        style={{ width: size, height: size }}
      />
      {withWordmark && (
        <span className="flex min-w-0 flex-col leading-none">
          <span
            className={clsx(
              'truncate font-display text-[0.95rem] font-extrabold tracking-tight',
              onOrange && 'text-[var(--cd-black)]',
            )}
          >
            CYCLO{' '}
            <span className={onOrange ? undefined : 'text-brand-text'}>DAKAR</span>
          </span>
          <span
            className={clsx(
              'truncate text-[0.6875rem]',
              onOrange ? 'text-black/65' : 'text-[var(--cd-text-muted)]',
            )}
          >
            Plateforme du club
          </span>
        </span>
      )}
    </span>
  )
}
