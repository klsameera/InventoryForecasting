$ErrorActionPreference = 'Stop'

# Stable entry point. The current builder is kept separate so the presentation
# can be replaced without changing the command documented for presenters.
& (Join-Path $PSScriptRoot 'build-management-presentation-current.ps1')
