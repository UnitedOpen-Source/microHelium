{{-- Issue #397 -- o seletor de idioma. Um botao por idioma, e nao um
     <select>: um clique, sem JavaScript (a CSP nao precisa de excecao), e sem
     mudar de pagina ao mudar o foco (WCAG 3.2.2). Cada nome esta escrito no
     proprio idioma e leva `lang`, para o leitor de tela pronuncia-lo certo. --}}
<form method="POST" action="{{ route('locale.update') }}" class="flex items-center gap-1" role="group" aria-label="{{ __('Idioma') }}">
    @csrf
    @foreach(\App\Support\InterfaceLocale::supported() as $localeCode => $localeName)
        <button type="submit" name="locale" value="{{ $localeCode }}" lang="{{ \App\Support\InterfaceLocale::htmlLang($localeCode) }}" aria-pressed="{{ app()->getLocale() === $localeCode ? 'true' : 'false' }}" class="rounded-md px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground aria-pressed:bg-accent aria-pressed:text-foreground transition-colors">{{ $localeName }}</button>
    @endforeach
</form>
