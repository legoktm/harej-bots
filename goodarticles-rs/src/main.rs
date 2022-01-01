use anyhow::{anyhow, Result};
use hblib::{print_diff, setup_logging};
use indexmap::IndexMap;
use log::{debug, error, info, warn};
use mwbot::generators::embeddedin;
use mwbot::parsoid::*;
use mwbot::{Bot, Error, Page, SaveOptions};
use std::cmp::Ordering;

const ENABLE_EDITS: bool = false;

/// Load users that don't want messages sent on their behalf
async fn skip_notify_users(bot: &Bot) -> Result<Vec<String>> {
    let page = bot.get_page("User:GA bot/Don't notify users for me");
    let code = page.get_html().await?;
    Ok(code
        .filter_links()
        .iter()
        .filter_map(|link| {
            let title = link.target();
            if title.starts_with("User:") {
                Some(title.trim_start_matches("User:").to_string())
            } else {
                None
            }
        })
        .collect())
}

/// Parse `[[User:GA bot/Stats]]` and turn it into a hashmap
async fn ga_stats(bot: &Bot) -> Result<IndexMap<String, u32>> {
    let page = bot.get_page("User:GA bot/Stats");
    let code = page.get_html().await?;
    Ok(code
        .select_first("table")
        .ok_or_else(|| anyhow!("Cannot find table on User:GA bot/Stats"))?
        .select("tr")
        .iter()
        .filter_map(|tr| {
            let children = tr.select("td");
            if children.len() != 2 {
                return None;
            }
            // We can use text_contents() on each child since it works out. Yay.
            let count = children[1].text_contents().trim().to_string();
            if count == "Reviews" {
                // Skip the first <th> row;
                return None;
            }
            Some((
                children[0].text_contents().trim().to_string(),
                // TODO: avoid unwrap
                count.parse().unwrap(),
            ))
        })
        .collect())
}

/// Save the current stats to `[[User:GA bot/Stats]]` for Wikipedians and as storage
async fn save_stats(bot: &Bot, stats: &mut IndexMap<String, u32>) -> Result<()> {
    // We're going to recreate the table from scratch, hopefully doesn't cause too many dirty diffs
    let code =
        Wikicode::new(r#"<table class="wikitable"><tr><th>User</th><th>Reviews</th></tr></table>"#);
    // unwrap is safe, we just inserted the table above
    let table = code.select_first("table").unwrap();
    // Resort by number of reviews
    stats.sort_by(|k1, v1, k2, v2| {
        let v = v1.cmp(v2);
        if v != Ordering::Equal {
            return v;
        }
        // Use username for tiebreaker
        k1.cmp(k2)
    });
    for (username, count) in stats.iter().rev() {
        let link = WikiLink::new(&format!("User:{}", username), &Wikicode::new_text(username));
        let td1 = Wikicode::new_node("td");
        td1.append(&link);
        let tr = Wikicode::new_node("tr");
        tr.append(&td1);
        let td2 = Wikicode::new_node("td");
        td2.append(&Wikicode::new_text(&count.to_string()));
        tr.append(&td2);
        table.append(&tr);
    }
    let page = bot.get_page("User:GA bot/Stats");
    let wikitext = bot.get_parsoid().transform_to_wikitext(&code).await?;
    let original = page.get_wikitext().await?;
    print_diff(&original, &wikitext);

    Ok(())
}

fn clean_status(current: String) -> String {
    match current.to_lowercase().as_str() {
        "on hold" | "onhold" | "hold" => "on hold",
        "on review" | "onreview" | "review" => "on review",
        // srsly
        "2nd opinion" | "2ndopinion" | "2ndop" | "second opinion" | "secondopinion"
        | "secondop" | "2 opinion" | "2opinion" | "2op" => "2nd opinion",
        unknown => unknown,
    }
    .to_string()
}

fn find_userlink(links: &[WikiLink]) -> Option<String> {
    for link in links {
        let target = link.target();
        for prefix in &[
            "User:",
            "User talk:",
            "Special:Contributions/",
            "Special:Contribs/",
        ] {
            if target.starts_with(prefix) {
                return Some(target.trim_start_matches(prefix).to_string());
            }
        }
    }
    None
}

fn extract_reviewer(code: &Wikicode) -> Option<String> {
    for bold in code.select("b") {
        if bold.text_contents() != "Reviewer:" {
            continue;
        }
        // Look through the sibling nodes for a link that is typically in a signature
        let links: Vec<_> = bold
            .following_siblings()
            .filter_map(|node| node.as_wikilink())
            .collect();
        return find_userlink(&links);
    }

    None
}

fn extract_nominator(code: &Wikicode) -> Option<String> {
    // FIXME: use a much better selector here
    for node in code.select("small") {
        if node.text_contents().starts_with("Nominated by") {
            return find_userlink(&node.filter_links());
        }
    }
    None
}

/// Find the first instance of a specific template
/// TODO: upstream into parsoid-rs?
fn find_template(code: &Wikicode, name: &str) -> Result<Option<Template>> {
    for temp in code.filter_templates()? {
        if temp.name() == name {
            return Ok(Some(temp));
        }
    }

    Ok(None)
}

fn has_ganotice(code: &Wikicode, title: &str, result: Option<&str>) -> bool {
    let find = if let Some(result) = result {
        format!("Template:GANotice result={}", result)
    } else {
        "Template:GANotice".to_string()
    };
    for comment in code.filter_comments() {
        if comment.text().trim() == find {
            // If the comment is a match, then check that the correct article
            // is linked before it
            // TODO: we should really have the template include the article name
            for link in comment
                .preceding_simblings()
                .filter_map(|node| node.as_wikilink())
            {
                if link.target() == title {
                    return true;
                }
            }
        }
    }

    false
}

// TODO: upstream to mwbot
async fn add_new_section(bot: &Bot, page: &Page, text: &str, summary: &str) -> Result<()> {
    let params = [
        ("action", "edit"),
        ("title", page.title()),
        ("text", text),
        ("summary", summary),
        ("bot", "1"),
        // v-- literally the only new line in this function
        ("section", "new"),
    ];

    let result = bot.get_api().post_with_token("csrf", &params).await?;
    match result["edit"]["result"].as_str() {
        Some("Success") => Ok(()),
        Some(code) => Err(anyhow!("Editing error: {} on {}", code, page.title())),
        None => Err(anyhow!("Unknown editing error on {}", page.title())),
    }
}

struct Nomination {
    name: String,
    subpage: u32,
    exists: bool,
}

impl Nomination {
    fn new_from_template(temp: &Template) -> Result<Self> {
        let params = temp.get_params();
        let name = params
            .get("1")
            .ok_or_else(|| anyhow!("No title specified in GANEntry"))?;
        let subpage: u32 = params
            .get("2")
            .ok_or_else(|| anyhow!("No title specified in GANEntry"))?
            .parse()?;
        let exists = match params.get("exists") {
            Some(val) => val == "yes",
            None => false,
        };
        Ok(Self {
            name: name.to_string(),
            subpage,
            exists,
        })
    }
}

// THIS IS SUPER SLOW
fn get_existing_gans(code: &Wikicode) -> Result<Vec<Nomination>> {
    let mut gans = vec![];
    for temp in code.filter_templates()? {
        if temp.name() == "Template:GANentry" {
            gans.push(Nomination::new_from_template(&temp)?);
        }
    }

    Ok(gans)
}

/// Get transclusions from the Talk: namespace
async fn get_talk_transclusions(bot: &Bot, name: &str) -> Result<Vec<Page>> {
    debug!("Loading talk transclusions of {}", name);
    let mut pages = vec![];
    let mut gen = embeddedin(bot, name);
    while let Some(page) = gen.recv().await {
        let page = page?;
        if page.title().starts_with("Talk:") {
            pages.push(page);
        }
    }

    Ok(pages)
}

#[tokio::main]
async fn main() {
    let logger = setup_logging("goodarticles_rs");
    match run().await {
        Ok(_) => info!("Finished successfully"),
        Err(e) => error!("Error: {}", e.to_string()),
    };
    logger.shutdown();
}

async fn run() -> Result<()> {
    let bot = Bot::from_default_config().await?;
    let _skip_notify_users = skip_notify_users(&bot).await?;
    let mut stats = ga_stats(&bot).await?;
    // let talk_pages = get_talk_transclusions(&api, "Template:GA nominee").await?;
    let talk_pages: Vec<String> = vec![];
    for talk_page in talk_pages {
        info!("Procesing {}", talk_page);
        let title = talk_page.trim_start_matches("Talk:").to_string();
        let talk = bot.get_page(&talk_page);
        let talk_code = talk.get_html().await?;
        let temp = match find_template(&talk_code, "Template:GA nominee")? {
            Some(temp) => temp,
            None => {
                warn!("Didn't find GA nominee template on {}, skipping", talk_page);
                continue;
            }
        };
        // FIXME: avoid unwrap()
        let reviewpage = bot.get_page(&format!(
            "{}/GA{}",
            talk_page,
            temp.get_param("page").unwrap()
        ));
        debug!("Examining the review page at {}", reviewpage.title());
        let reviewpage_code = match reviewpage.get_html().await {
            Ok(code) => code,
            Err(Error::PageDoesNotExist(name)) => {
                info!("{} does not exist yet, skipping.", name);
                continue;
            }
            Err(e) => {
                return Err(e.into());
            }
        };
        let reviewer = match extract_reviewer(&reviewpage_code) {
            Some(reviewer) => reviewer,
            None => {
                warn!("Couldn't find reviewer on {}, skipping", reviewpage.title());
                continue;
            }
        };
        let status = match temp.get_param("status").map(clean_status) {
            Some(status) => status,
            None => {
                warn!(
                    "Couldn't find a status for {}, skipping",
                    reviewpage.title()
                );
                continue;
            }
        };
        match status.as_str() {
            "new" => {
                temp.set_param("status", "on review")?;
                // Transclude the GA review if not already done
                if find_template(&talk_code, reviewpage.title())?.is_none() {
                    let new_temp = Template::new_simple(reviewpage.title());
                    // Put some whitespace before
                    talk_code.append(&Wikicode::new_text("\n\n"));
                    talk_code.append(&new_temp);
                }

                let current = match talk.get_wikitext().await {
                    Ok(text) => text,
                    Err(e) => {
                        error!(
                            "Error fetching wikitext of {}, skipping: {}",
                            talk.title(),
                            e.to_string()
                        );
                        continue;
                    }
                };
                let new_wikitext = bot.get_parsoid().transform_to_wikitext(&talk_code).await?;
                if print_diff(&current, &new_wikitext) && ENABLE_EDITS {
                    talk.save(
                        talk_code.clone(),
                        &SaveOptions::summary("Transcluding GA review"),
                    )
                    .await?;
                }

                // Notify the nominator
                let nominator = match extract_nominator(&talk_code) {
                    Some(nom) => nom,
                    None => {
                        warn!("Couldn't find nominator on {}, skipping", talk.title());
                        continue;
                    }
                };
                // TODO: follow redirect
                let nom_talk = bot.get_page(&format!("User talk:{}", nominator));
                let nom_talk_code = nom_talk.get_html().await?;
                if !has_ganotice(&nom_talk_code, &title, None) {
                    // Yeah, wikitext sucks but it's too annoying to build with Parsoid. Maybe we need a new wrapper around GANotice.
                    let message = format!(
                        "{{{{subst:GANotice|article={0}|days=7|reviewlink={1}}}}} <small>Message delivered by [[User:Legobot|]], on behalf of [[User:{2}|]]</small> -- {{{{subst:user0|User={2}}}}} ~~~~~",
                        title, reviewpage.title(), reviewer
                    );
                    let summary = format!("Your [[WP:GA|GA]] nomination of [[{}]]", title);
                    // TODO: {{nobots}} check
                    add_new_section(&bot, &nom_talk, &message, &summary).await?;
                }
            }
            _ => {}
        };
    }

    // Save new stats back
    save_stats(&bot, &mut stats).await?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::Path;

    async fn bot() -> Bot {
        Bot::from_path(&Path::new("mwbot-test.toml")).await.unwrap()
    }

    #[tokio::test]
    async fn test_skip_notify_users() -> Result<()> {
        let bot = bot().await;
        let skip = skip_notify_users(&bot).await?;
        assert!(skip.contains(&"SilkTork".to_string()));
        Ok(())
    }

    #[tokio::test]
    async fn test_extract_reviewer() -> Result<()> {
        let bot = bot().await;
        // TODO: try some more esoteric signatures
        let page = bot.get_page("Talk:Port Adelaide Football Club/GA2");
        let code = page.get_html().await?;
        let reviewer = extract_reviewer(&code);
        assert_eq!(reviewer.unwrap(), "Sportsfan77777".to_string());
        Ok(())
    }

    #[tokio::test]
    async fn test_extract_nominator() -> Result<()> {
        let bot = bot().await;
        let page = bot.get_page("Talk:Aluminium");
        let code = page.get_revision_html(1005044564).await?;
        let nominator = extract_nominator(&code);
        assert_eq!(nominator.unwrap(), "R8R".to_string());
        Ok(())
    }

    #[tokio::test]
    async fn test_has_ganotice() -> Result<()> {
        let bot = bot().await;
        let page = bot.get_page("User_talk:R8R");
        let code = page.get_revision_html(1005044565).await?;
        assert!(has_ganotice(&code, "Aluminium", None));
        assert!(!has_ganotice(&code, "Copper", None));
        Ok(())
    }

    #[tokio::test]
    async fn test_get_existing_gans() -> Result<()> {
        let bot = bot().await;
        let page = bot.get_page("Wikipedia:Good article nominations");
        let code = page.get_revision_html(1007062898).await?;
        let gans = get_existing_gans(&code)?;
        {
            let gan = &gans[0];
            assert_eq!(gan.name, "The Liquor Store".to_string());
            assert_eq!(gan.subpage, 1);
            assert!(gan.exists);
        }
        assert_eq!(gans.len(), 0);
        Ok(())
    }

    #[test]
    fn test_clean_status() {
        assert_eq!(clean_status("new".to_string()), "new".to_string());
        assert_eq!(clean_status("hold".to_string()), "on hold".to_string());
        assert_eq!(clean_status("2op".to_string()), "2nd opinion".to_string());
    }
}
