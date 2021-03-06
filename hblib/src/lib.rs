use anyhow::{anyhow, Result};
use flexi_logger::LoggerHandle;
use log::info;
use mediawiki::api::Api;
use serde::Deserialize;

// Re-export to centralize the dependency
pub use mediawiki;

/// Login information, stored in auth.toml
#[derive(Deserialize)]
struct Auth {
    username: String,
    password: String,
}

pub async fn mwapi(user_agent: &str) -> Result<Api> {
    let mut api = Api::new("https://en.wikipedia.org/w/api.php").await?;
    api.set_user_agent(user_agent);
    api.set_edit_delay(Some(10_000));
    Ok(api)
}

pub async fn mwapi_auth(user_agent: &str) -> Result<Api> {
    let mut api = mwapi(user_agent).await?;
    let path = {
        let first = std::path::Path::new("auth.toml");
        if first.exists() {
            first.to_path_buf()
        } else {
            dirs_next::home_dir()
                .ok_or_else(|| anyhow!("Cannot find home directory"))?
                .join("auth.toml")
        }
    };
    info!("Reading credentials from {:?}", path);
    let auth: Auth = toml::from_str(&std::fs::read_to_string(path)?)?;
    info!("Logging in as {}", auth.username);
    api.login(auth.username, auth.password).await?;
    Ok(api)
}

/// Return whether changes were made
pub fn print_diff(old: &str, new: &str) -> bool {
    use similar::TextDiff;
    let diff = TextDiff::from_lines(old, new).unified_diff().to_string();
    for line in diff.split('\n') {
        info!("{}", line);
    }
    !diff.trim().is_empty()
}

pub fn setup_logging(name: &str) -> LoggerHandle {
    use flexi_logger::{opt_format, Cleanup, Criterion, Duplicate, Logger, Naming};
    Logger::with_str(format!("info, {}=debug", name))
        .log_to_file()
        .duplicate_to_stdout(Duplicate::Info)
        .format(opt_format)
        .append()
        .use_buffering(true)
        .rotate(
            Criterion::Size(5_000_000),
            Naming::Timestamps,
            Cleanup::KeepLogFiles(30),
        )
        .start()
        .unwrap()
}
